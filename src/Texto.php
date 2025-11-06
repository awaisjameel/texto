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
     * @param  string  $to  E.164 formatted recipient number
     * @param  string  $body  Message body
     * @param  array{media_urls?:string[], metadata?:array, from?:string, driver?:string}  $options
     */
    public function send(string $to, string $body, array $options = []): SentMessageResult
    {
        $driverName = Arr::get($options, 'driver');
        $sender = $driverName
            ? $this->driverManager->sender(Driver::from($driverName))
            : $this->driverManager->sender();

        $toNumber = PhoneNumber::fromString($to);
        $fromNumber = isset($options['from']) ? PhoneNumber::fromString($options['from']) : null;
        // Resolve effective default 'from' (so queued row uses same as final send) if still null
        if (! $fromNumber) {
            $activeDriver = $driverName ?: config('texto.driver', 'twilio');
            $rawFrom = null;
            if ($activeDriver === Driver::Twilio->value) {
                $rawFrom = config('texto.twilio.from_number');
            } elseif ($activeDriver === Driver::Telnyx->value) {
                $rawFrom = config('texto.telnyx.from_number');
            }
            if ($rawFrom) {
                try {
                    $fromNumber = PhoneNumber::fromString($rawFrom);
                } catch (\Throwable $e) {
                    Log::warning('Texto default from number invalid', ['from' => $rawFrom, 'error' => $e->getMessage()]);
                }
            }
        }
        $media = $options['media_urls'] ?? [];
        $metadata = $options['metadata'] ?? [];

        // Queue mode: create queued result, persist, dispatch job (only on initial call, not inside queued job)
        if (config('texto.queue', false) && empty($options['queued_job'])) {
            $queuedResult = new SentMessageResult(
                $driverName ? Driver::from($driverName) : Driver::from(config('texto.driver', 'twilio')),
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
            Bus::dispatch(new SendMessageJob($record?->id ?? 0, $toNumber->e164, $body, [
                'from' => $fromNumber?->e164,
                'media_urls' => $media,
                'metadata' => $metadata,
                'driver' => $driverName,
            ]));

            return $queuedResult;
        }

        try {
            /** @var MessageSenderInterface $sender */
            $result = $sender->send($toNumber, $body, $fromNumber, $media, $metadata);
        } catch (TextoSendFailedException $e) {
            Log::error('Texto send failed: '.$e->getMessage(), ['driver' => $driverName]);
            $failed = new SentMessageResult(
                $driverName ? Driver::from($driverName) : Driver::from(config('texto.driver', 'twilio')),
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
                if ($queuedMessageId && method_exists($this->messages, 'upgradeQueued')) {
                    $upgraded = $this->messages->upgradeQueued((int) $queuedMessageId, $result);
                    if (! $upgraded) {
                        // If deterministic upgrade fails (row missing or already terminal) create new record for audit trail.
                        $this->messages->storeSent($result);
                    }
                } else {
                    // Without ID, just create a fresh record (deterministic path unavailable)
                    $this->messages->storeSent($result);
                }
            } else {
                $this->messages->storeSent($result);
            }
        }
        event(new MessageSent($result));

        return $result;
    }
}
