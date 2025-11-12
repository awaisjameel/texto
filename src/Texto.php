<?php

declare(strict_types=1);

namespace Awaisjameel\Texto;

use Awaisjameel\Texto\Contracts\DriverManagerInterface;
use Awaisjameel\Texto\Contracts\MessageRepositoryInterface;
use Awaisjameel\Texto\Contracts\MessageSenderInterface;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Enums\MessageStatus;
use Awaisjameel\Texto\Events\MessageFailed;
use Awaisjameel\Texto\Events\MessageSent;
use Awaisjameel\Texto\Exceptions\TextoSendFailedException;
use Awaisjameel\Texto\Jobs\SendMessageJob;
use Awaisjameel\Texto\ValueObjects\PhoneNumber;
use Awaisjameel\Texto\ValueObjects\SentMessageResult;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class Texto
{
    public function __construct(
        protected DriverManagerInterface $driverManager,
        protected MessageRepositoryInterface $messages
    ) {}

    /**
     * Send an SMS/MMS message using the active (or overridden) driver.
     *
     * @param  string  $to  Recipient phone number (E.164 format or local format)
     * @param  string  $body  Message body text
     * @param  array{media_urls?:string[], metadata?:array, from?:string, driver?:string, driver_config?:array<string,mixed>, queued_job?:bool, queued_message_id?:int}  $options
     *
     * @throws TextoSendFailedException
     */
    public function send(string $to, string $body, array $options = []): SentMessageResult
    {
        $driverName = Arr::get($options, 'driver');
        $driverConfigOverride = Arr::get($options, 'driver_config');

        $toNumber = PhoneNumber::fromString($to);
        $fromNumber = isset($options['from']) ? PhoneNumber::fromString($options['from']) : null;
        // Resolve effective default 'from' (so queued row uses same as final send) if still null
        if (! $fromNumber) {
            $activeDriver = $driverName ?: config('texto.driver', 'twilio');
            $driverConfig = config("texto.{$activeDriver}", []);
            $rawFrom = $driverConfig['from_number'] ?? null;

            if ($rawFrom) {
                try {
                    $fromNumber = PhoneNumber::fromString($rawFrom);
                } catch (\Throwable $e) {
                    Log::warning('Texto default from number invalid', [
                        'from' => $rawFrom,
                        'driver' => $activeDriver,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
        $media = $options['media_urls'] ?? [];
        $metadata = $options['metadata'] ?? [];

        return $this->withDriverConfigOverride($driverName, is_array($driverConfigOverride) ? $driverConfigOverride : null, function () use ($driverName, $options, $toNumber, $fromNumber, $body, $media, $metadata) {
            $sender = $driverName
                ? $this->driverManager->sender(Driver::from($driverName))
                : $this->driverManager->sender();

            // Queue mode: create queued result, persist, dispatch job (only on initial call, not inside queued job)
            if (config('texto.queue', false) && empty($options['queued_job'])) {
                $currentDriver = $driverName ?: config('texto.driver', 'twilio');
                $queuedResult = new SentMessageResult(
                    Driver::from($currentDriver),
                    \Awaisjameel\Texto\Enums\Direction::Sent,
                    $toNumber,
                    $fromNumber,
                    $body,
                    $media,
                    $metadata,
                    MessageStatus::Queued,
                    null,
                );
                $record = null;
                if (config('texto.store_messages', true)) {
                    $record = $this->messages->storeSent($queuedResult);
                }
                // Dispatch with the exact queued message id (0 if not stored so upgrade falls back later)
                /** @var \Awaisjameel\Texto\Models\Message|null $record */
                $queuedId = $record ? (int) $record->id : 0; // concrete model has id
                Bus::dispatch(new SendMessageJob($queuedId, $toNumber->e164, $body, [
                    'from' => $fromNumber?->e164,
                    'media_urls' => $media,
                    'metadata' => $metadata,
                    'driver' => $currentDriver,
                    'driver_config' => config("texto.{$currentDriver}", []),
                ]));

                return $queuedResult;
            }

            try {
                /** @var MessageSenderInterface $sender */
                $result = $sender->send($toNumber, $body, $fromNumber, $media, $metadata);
            } catch (TextoSendFailedException $e) {
                Log::error('Texto send failed', [
                    'driver' => $driverName ?: config('texto.driver', 'twilio'),
                    'to' => $toNumber->e164,
                    'from' => $fromNumber?->e164,
                    'error' => $e->getMessage(),
                ]);
                $currentDriver = $driverName ?: config('texto.driver', 'twilio');
                $failed = new SentMessageResult(
                    Driver::from($currentDriver),
                    \Awaisjameel\Texto\Enums\Direction::Sent,
                    $toNumber,
                    $fromNumber,
                    $body,
                    $media,
                    $metadata,
                    MessageStatus::Failed,
                    null,
                    'send_failed'
                );
                event(new MessageFailed($failed, $e->getMessage()));
                if (config('texto.store_messages', true)) {
                    $this->messages->storeSent($failed);
                }

                return $failed;
            }

            if (config('texto.store_messages', true)) {
                if (! empty($options['queued_job'])) {
                    $queuedMessageId = $options['queued_message_id'] ?? null;
                    if ($queuedMessageId) {
                        $upgraded = $this->messages->upgradeQueued((int) $queuedMessageId, $result);
                        if (! $upgraded) {
                            // If deterministic upgrade fails (row missing or already terminal) create new record for audit trail.
                            Log::debug('Texto queued message upgrade failed, creating new record', [
                                'queued_message_id' => $queuedMessageId,
                                'provider_id' => $result->providerMessageId,
                            ]);
                            $this->messages->storeSent($result);
                        }
                    } else {
                        // Without ID, just create a fresh record (deterministic path unavailable)
                        Log::debug('Texto queued message upgrade unavailable, creating new record', [
                            'has_queued_message_id' => $queuedMessageId !== null,
                            'provider_id' => $result->providerMessageId,
                        ]);
                        $this->messages->storeSent($result);
                    }
                } else {
                    $this->messages->storeSent($result);
                }
            }
            event(new MessageSent($result));

            return $result;
        });
    }

    /**
     * @template T
     *
     * @param  array<string, mixed>|null  $override
     * @param  Closure():T  $callback
     * @return T
     */
    private function withDriverConfigOverride(?string $driverName, ?array $override, Closure $callback): mixed
    {
        if ($driverName === null || $override === null) {
            return $callback();
        }

        $path = "texto.{$driverName}";
        $original = config($path);
        $merged = is_array($original)
            ? array_replace_recursive($original, $override)
            : $override;

        config([$path => $merged]);

        try {
            return $callback();
        } finally {
            config([$path => $original]);
        }
    }
}
