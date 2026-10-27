<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Jobs;

use Awaisjameel\Texto\Contracts\DriverManagerInterface;
use Awaisjameel\Texto\Contracts\MessageRepositoryInterface;
use Awaisjameel\Texto\Contracts\PollableMessageSenderInterface;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Enums\MessageStatus;
use Awaisjameel\Texto\Models\Message;
use Awaisjameel\Texto\Support\Configuration;
use Awaisjameel\Texto\Support\PollingParameterResolver;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;

/**
 * Poll provider APIs for messages stuck in transient states (queued/sending/sent) when webhooks are delayed or absent.
 */
class StatusPollJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('texto-status-poll'))->dontRelease()->expireAfter(180)];
    }

    public function handle(MessageRepositoryInterface $messages, DriverManagerInterface $drivers): void
    {
        $enabled = config('texto.status_polling.enabled', false);
        if (! $enabled) {
            return;
        }

        $values = config('texto.status_polling', []);
        if (! is_array($values)) {
            throw new \InvalidArgumentException('Status polling configuration must be an array.');
        }
        $settings = new Configuration($values);
        $minAge = $settings->integer('min_age_seconds', 60);
        $maxAttempts = $settings->integer('max_attempts', 5, 1);
        $queuedMaxAttempts = $settings->integer('queued_max_attempts', 2, 1);
        $backoff = $settings->integer('backoff_seconds', 300);
        $batch = $settings->integer('batch_limit', 100, 1);

        $transientStatuses = [
            MessageStatus::Queued->value,
            MessageStatus::Sending->value,
            MessageStatus::Sent->value,
        ];

        // Apply the batch limit to eligible polls, not the oldest unconditionally
        // selected rows: exhausted or cooling-down rows must not starve newer messages.
        $candidates = LazyCollection::make(function () use ($transientStatuses, $minAge, $batch) {
            foreach ([true, false] as $missingId) {
                $query = Message::query()->whereIn('status', $transientStatuses)
                    ->where('created_at', '<=', now()->subSeconds($minAge));
                $missingId ? $query->whereNull('provider_message_id') : $query->whereNotNull('provider_message_id');
                yield from $query->lazyById($batch);
            }
        });
        $checkedCount = 0;
        $polledCount = 0;
        foreach ($candidates as $message) {
            if ($polledCount >= $batch) {
                break;
            }
            $checkedCount++;
            $currentStatus = MessageStatus::tryFrom($message->status ?? '');
            if ($currentStatus === null) {
                continue;
            }
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
            try {
                $sender = $drivers->sender($driverEnum, $message->driver_config);
            } catch (\Throwable $exception) {
                report($exception);

                continue;
            }
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
                $messages->updatePolledStatus($message, $currentStatus, ['poll_note' => 'fetch-failed']);
                $polledCount++;
                Log::warning('Status poll fetch failed', [
                    'driver' => $message->driver,
                    'provider_id' => $providerId,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if (! $newStatus) {
                // Increment attempt even if no status returned
                $messages->updatePolledStatus($message, $currentStatus, [
                    'poll_note' => 'no-status-returned',
                ]);
                $polledCount++;

                continue;
            }

            // Persist forward-only progression. Terminal states fetched from the provider are
            // authoritative and always stored; transient states only advance the row (never regress).
            // Ranking/terminal logic lives on the MessageStatus enum so this stays in lock-step with
            // the webhook persistence path. Metadata flag 'poll_promoted' marks a transient advancement.
            $current = $currentStatus;
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
                'checked' => $checkedCount,
                'polled' => $polledCount,
            ]);
        }
    }
}
