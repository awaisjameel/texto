<?php

declare(strict_types=1);

namespace Awaisjameel\Texto;

use Awaisjameel\Texto\Contracts\DriverManagerInterface;
use Awaisjameel\Texto\Contracts\MessageRepositoryInterface;
use Awaisjameel\Texto\Enums\Direction;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Enums\MessageStatus;
use Awaisjameel\Texto\Events\MessageFailed;
use Awaisjameel\Texto\Events\MessageSent;
use Awaisjameel\Texto\Exceptions\TextoSendFailedException;
use Awaisjameel\Texto\Jobs\SendMessageJob;
use Awaisjameel\Texto\Models\Message;
use Awaisjameel\Texto\Support\AddressResolver;
use Awaisjameel\Texto\Support\Configuration;
use Awaisjameel\Texto\ValueObjects\EmailAddress;
use Awaisjameel\Texto\ValueObjects\SentMessageResult;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class Texto
{
    public function __construct(
        protected DriverManagerInterface $driverManager,
        protected MessageRepositoryInterface $messages
    ) {}

    /**
     * Send a message (SMS/MMS/WhatsApp/Email) using the active (or overridden) driver.
     *
     * Email-specific options (subject, html, cc, bcc, reply_to, attachments) are only
     * consumed by the email driver and are persisted inside the message metadata.
     *
     * @param  string  $to  Recipient phone number (E.164 or local format) or email address
     * @param  string  $body  Message body text (plain text)
     * @param  array{media_urls?:string[], metadata?:array<string,mixed>, from?:string|null, driver?:string, driver_config?:array<string,mixed>, subject?:string, html?:string, cc?:string[], bcc?:string[], reply_to?:string, attachments?:array<int, string|array{path?:string, url?:string, name?:string, mime?:string}>, queued_job?:bool, queued_message_id?:int}  $options
     *
     * @throws TextoSendFailedException
     */
    public function send(string $to, string $body, array $options = []): SentMessageResult
    {
        return $this->performSend($to, $body, $options, false);
    }

    /**
     * Send a message immediately, bypassing the queue even if enabled.
     *
     * @param  string  $to  Recipient phone number (E.164 or local format) or email address
     * @param  string  $body  Message body text (plain text)
     * @param  array{media_urls?:string[], metadata?:array<string,mixed>, from?:string|null, driver?:string, driver_config?:array<string,mixed>, subject?:string, html?:string, cc?:string[], bcc?:string[], reply_to?:string, attachments?:array<int, string|array{path?:string, url?:string, name?:string, mime?:string}>, queued_job?:bool, queued_message_id?:int}  $options
     *
     * @throws TextoSendFailedException
     */
    public function sendDirect(string $to, string $body, array $options = []): SentMessageResult
    {
        return $this->performSend($to, $body, $options, true);
    }

    /**
     * @param  array{media_urls?:string[], metadata?:array<string,mixed>, from?:string|null, driver?:string, driver_config?:array<string,mixed>, subject?:string, html?:string, cc?:string[], bcc?:string[], reply_to?:string, attachments?:array<int, string|array{path?:string, url?:string, name?:string, mime?:string}>, queued_job?:bool, queued_message_id?:int}  $options
     */
    private function performSend(string $to, string $body, array $options, bool $forceSynchronous): SentMessageResult
    {
        $driverName = $options['driver'] ?? config('texto.driver', 'twilio');
        if (! is_string($driverName)) {
            throw new \InvalidArgumentException('The message driver must be a string.');
        }
        $driver = Driver::from($driverName);
        $driverConfiguration = Configuration::forDriver($driver);
        if (isset($options['driver_config'])) {
            $driverConfiguration = array_replace_recursive($driverConfiguration, $options['driver_config']);
        }
        $toAddress = AddressResolver::parse($to);
        $fromAddress = isset($options['from']) ? AddressResolver::parse($options['from']) : AddressResolver::defaultFrom($driver, $driverConfiguration);
        $media = $options['media_urls'] ?? [];
        $metadata = $this->foldEmailOptionsIntoMetadata($options, $options['metadata'] ?? []);
        $sender = $this->driverManager->sender($driver, $driverConfiguration);

        // Queue mode: create queued result, persist, dispatch job (only on initial call, not inside queued job)
        if (! $forceSynchronous && config('texto.queue', false) && empty($options['queued_job'])) {
            $currentDriver = $driver->value;
            $queuedResult = new SentMessageResult(
                $driver,
                Direction::Sent,
                $toAddress,
                $fromAddress,
                $body,
                $media,
                $metadata,
                MessageStatus::Queued,
                null,
            );
            $record = null;
            if (config('texto.store_messages', true)) {
                $record = $this->messages->storeSent($queuedResult, $driverConfiguration);
            }
            // Dispatch with the exact queued message id (0 if not stored so upgrade falls back later)
            $queuedId = $record->id ?? 0;
            Bus::dispatch(new SendMessageJob($queuedId, $toAddress instanceof EmailAddress ? $toAddress->formatted() : $toAddress->value(), $body, [
                'from' => $fromAddress instanceof EmailAddress ? $fromAddress->formatted() : $fromAddress?->value(),
                'media_urls' => $media,
                'metadata' => $metadata,
                'driver' => $currentDriver,
                'driver_config' => $driverConfiguration,
            ]));

            return $queuedResult;
        }

        try {
            $result = $sender->send($toAddress, $body, $fromAddress, $media, $metadata);
        } catch (TextoSendFailedException $e) {
            Log::error('Texto send failed', [
                'driver' => $driverName ?: config('texto.driver', 'twilio'),
                'to' => $toAddress->value(),
                'from' => $fromAddress?->value(),
                'error' => $e->getMessage(),
            ]);
            $failed = new SentMessageResult(
                $driver,
                Direction::Sent,
                $toAddress,
                $fromAddress,
                $body,
                $media,
                $metadata,
                MessageStatus::Failed,
                null,
                'send_failed'
            );
            $this->persistResult($failed, $options, $driverConfiguration);
            event(new MessageFailed($failed, $e->getMessage()));

            return $failed;
        }

        $this->persistResult($result, $options, $driverConfiguration);
        event(new MessageSent($result));

        return $result;
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $configuration
     */
    private function persistResult(SentMessageResult $result, array $options, array $configuration): void
    {
        if (! config('texto.store_messages', true)) {
            return;
        }
        $id = $options['queued_message_id'] ?? null;
        if (! empty($options['queued_job']) && is_int($id) && $id > 0) {
            if ($this->messages->upgradeQueued($id, $result, $configuration) !== null) {
                return;
            }
        }
        $this->messages->storeSent($result, $configuration);
    }

    /**
     * Email-specific send options ride inside metadata under the 'email' key so they persist
     * with the stored message and survive the queue round-trip (SendMessageJob re-enters
     * performSend with metadata intact but without the original top-level options).
     *
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function foldEmailOptionsIntoMetadata(array $options, array $metadata): array
    {
        $emailOptions = array_filter([
            'subject' => $options['subject'] ?? null,
            'html' => $options['html'] ?? null,
            'cc' => $options['cc'] ?? null,
            'bcc' => $options['bcc'] ?? null,
            'reply_to' => $options['reply_to'] ?? null,
            'attachments' => $options['attachments'] ?? null,
        ], static fn ($value) => $value !== null);

        if ($emailOptions === []) {
            return $metadata;
        }

        $existing = is_array($metadata['email'] ?? null) ? $metadata['email'] : [];
        // Explicit top-level options win over anything already present in metadata.
        $metadata['email'] = array_merge($existing, $emailOptions);

        return $metadata;
    }
}
