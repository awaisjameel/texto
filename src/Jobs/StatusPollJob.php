<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Jobs;

use Awaisjameel\Texto\Contracts\DriverManagerInterface;
use Awaisjameel\Texto\Contracts\MessageRepositoryInterface;
use Awaisjameel\Texto\Contracts\PollableMessageSenderInterface;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Enums\MessageStatus;
use Awaisjameel\Texto\Models\Message;
use Awaisjameel\Texto\Support\PollingParameterResolver;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

/**
 * Poll provider APIs for messages stuck in transient states (queued/sending/sent) when webhooks are delayed or absent.
 */
class StatusPollJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(MessageRepositoryInterface $messages, DriverManagerInterface $drivers): void
    {
        $enabled = config('texto.status_polling.enabled', false);
        if (! $enabled) {
            return;
        }

        $minAge = (int) config('texto.status_polling.min_age_seconds', 60);
        $maxAttempts = (int) config('texto.status_polling.max_attempts', 5);
        $queuedMaxAttempts = (int) config('texto.status_polling.queued_max_attempts', 2);
        $backoff = (int) config('texto.status_polling.backoff_seconds', 300);
        $batch = (int) config('texto.status_polling.batch_limit', 100);

        $transientStatuses = [
            MessageStatus::Queued->value,
            MessageStatus::Sending->value,
            MessageStatus::Sent->value,
        ];

        // First pass: poll messages without provider IDs (queued/sending states)
        $query = Message::query()
            ->whereIn('status', $transientStatuses)
            ->where('created_at', '<=', now()->subSeconds($minAge))
            ->whereNull('provider_message_id')
            ->orderBy('id', 'asc')
            ->limit($batch);

        $candidates = $query->get();

        // Second pass: poll messages with provider IDs if we have capacity
        if ($candidates->count() < $batch) {
            $remainingCapacity = $batch - $candidates->count();
            $secondQuery = Message::query()
                ->whereIn('status', $transientStatuses)
                ->where('created_at', '<=', now()->subSeconds($minAge))
                ->whereNotNull('provider_message_id')
                ->orderBy('id', 'asc')
                ->limit($remainingCapacity);

            $candidates = $candidates->merge($secondQuery->get());
        }

        if ($candidates->isEmpty()) {
            return;
        }

        $polledCount = 0;
        foreach ($candidates as $message) {
            $meta = $message->metadata ?? [];
            $attempts = (int) ($meta['poll_attempts'] ?? 0);
            $lastPollAt = $meta['last_poll_at'] ?? null;
            // Queued rows get a lower attempt cap than other transient states.
            if ($message->status === MessageStatus::Queued->value && $attempts >= $queuedMaxAttempts) {
                continue;
            }
            if ($attempts >= $maxAttempts) {
                continue;
            }
            if ($lastPollAt) {
                try {
                    $last = Carbon::parse($lastPollAt);
                    if ($last->diffInSeconds(now()) < $backoff) {
                        continue;
                    }
                } catch (\Throwable $e) {
                    // Malformed timestamp: fall through and poll anyway.
                }
            }

            try {
                $driverEnum = Driver::from($message->driver);
            } catch (\Throwable $e) {
                continue;
            }
            $sender = $drivers->sender($driverEnum);
            if (! $sender instanceof PollableMessageSenderInterface) {
                continue;
            }

            $providerId = $message->provider_message_id;
            if (! $providerId) {
                // No provider id yet: each transient state has its own attempt cap before
                // we give up and mark the row Ambiguous.
                if ($message->status === MessageStatus::Queued->value) {
                    $nextAttempt = $attempts + 1;
                    if ($nextAttempt >= $queuedMaxAttempts) {
                        $messages->updatePolledStatus($message, MessageStatus::Ambiguous, [
                            'poll_terminal' => true,
                            'provider_id_missing' => true,
                        ]);
                    } else {
                        $messages->updatePolledStatus($message, MessageStatus::Queued, [
                            'provider_id_missing_pending' => true,
                        ]);
                    }
                    $polledCount++;

                    continue;
                }
                // Sending without provider id: same approach but allow up to maxAttempts.
                if ($message->status === MessageStatus::Sending->value) {
                    $nextAttempt = $attempts + 1;
                    if ($nextAttempt >= $maxAttempts) {
                        $messages->updatePolledStatus($message, MessageStatus::Ambiguous, [
                            'poll_terminal' => true,
                            'provider_id_missing' => true,
                            'provider_id_missing_sending' => true,
                        ]);
                    } else {
                        $messages->updatePolledStatus($message, MessageStatus::Sending, [
                            'provider_id_missing_pending' => true,
                        ]);
                    }
                    $polledCount++;

                    continue;
                }
                // Sent without provider id is unexpected: mark ambiguous immediately.
                if ($message->status === MessageStatus::Sent->value) {
                    $messages->updatePolledStatus($message, MessageStatus::Ambiguous, [
                        'poll_terminal' => true,
                        'provider_id_missing' => true,
                        'provider_id_missing_sent' => true,
                    ]);
                    $polledCount++;
                }

                continue;
            }

            $newStatus = null;
            try {
                $args = PollingParameterResolver::fetchStatusArgs($driverEnum, $message);
                if (empty($args)) {
                    // Should not happen given earlier provider id guard; continue defensively.
                    continue;
                }
                $providerArgument = array_shift($args);
                if (! is_string($providerArgument)) {
                    continue;
                }
                /** @var MessageStatus|null $fetched */
                $fetched = $sender->fetchStatus($providerArgument, ...$args);
                $newStatus = $fetched;
            } catch (\Throwable $e) {
                Log::warning('Status poll fetch failed', [
                    'driver' => $message->driver,
                    'provider_id' => $providerId,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if (! $newStatus) {
                // Increment attempt even if no status returned
                $messages->updatePolledStatus($message, MessageStatus::from($message->status), [
                    'poll_note' => 'no-status-returned',
                ]);
                $polledCount++;

                continue;
            }

            // Persist forward-only progression. Terminal states fetched from the provider are
            // authoritative and always stored; transient states only advance the row (never regress).
            // Ranking/terminal logic lives on the MessageStatus enum so this stays in lock-step with
            // the webhook persistence path. Metadata flag 'poll_promoted' marks a transient advancement.
            $current = MessageStatus::from($message->status);
            $terminal = $newStatus->isTerminal();
            $progression = $newStatus->rank() > $current->rank();
            $statusToStore = $terminal
                ? $newStatus // Always persist terminal
                : ($progression ? $newStatus : $current); // Promote if progressed

            $extraMeta = [];
            if ($terminal) {
                $extraMeta['poll_terminal'] = true;
            } else {
                $extraMeta['poll_transient'] = $newStatus->value;
                if ($progression) {
                    $extraMeta['poll_promoted'] = true;
                }
            }

            $messages->updatePolledStatus($message, $statusToStore, $extraMeta);
            $polledCount++;
        }

        if ($polledCount > 0) {
            Log::info('Texto status polling run completed', [
                'checked' => $candidates->count(),
                'polled' => $polledCount,
            ]);
        }
    }
}
