<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Webhooks;

use Awaisjameel\Texto\Contracts\WebhookHandlerInterface;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Exceptions\TextoWebhookValidationException;
use Awaisjameel\Texto\Support\StatusMapper;
use Awaisjameel\Texto\Support\WebhookData;
use Awaisjameel\Texto\ValueObjects\PhoneNumber;
use Awaisjameel\Texto\ValueObjects\WebhookProcessingResult;
use Awaisjameel\Texto\Webhooks\Concerns\ValidatesTelnyxSignature;
use Illuminate\Http\Request;

class TelnyxWebhookHandler implements WebhookHandlerInterface
{
    use ValidatesTelnyxSignature;

    /** @var array<string, mixed> */
    protected array $config;

    /**
     * @param  array<string, mixed>|null  $config
     */
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? config('texto.telnyx', []);
    }

    public function handle(Request $request): WebhookProcessingResult
    {
        $skip = config('texto.testing.skip_webhook_validation', false) && app()->environment('testing');
        if (! $skip) {
            $this->assertValidTelnyxSignature($request, $this->config);
        }

        $payload = WebhookData::body($request);
        $data = WebhookData::object($payload['data'] ?? null, 'data');
        $rawEvent = WebhookData::text($data, 'event_type');
        $eventType = $rawEvent !== null ? strtolower($rawEvent) : null;
        $messagePayload = $data['payload'] ?? null;
        if (! is_array($messagePayload)) {
            throw new TextoWebhookValidationException('Telnyx payload missing message data.');
        }

        $direction = strtolower(WebhookData::text($messagePayload, 'direction') ?? '');
        $isInbound = $this->isInboundEvent($eventType, $direction);

        return $isInbound
            ? $this->mapInboundPayload($messagePayload, $eventType)
            : $this->mapStatusPayload($messagePayload, $eventType);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function mapInboundPayload(array $payload, ?string $eventType): WebhookProcessingResult
    {
        $fromRaw = $payload['from']['phone_number'] ?? null;
        $toRaw = $payload['to'][0]['phone_number'] ?? ($payload['to']['phone_number'] ?? null);
        $from = $this->parsePhoneOrFail($fromRaw, 'from');
        $to = $this->parsePhoneOrFail($toRaw, 'to');
        $text = WebhookData::text($payload, 'text');
        $mediaItems = WebhookData::items($payload['media'] ?? [], 'media');
        $media = [];
        foreach ($mediaItems as $item) {
            if (is_string($item)) {
                $media[] = $item;
            } else {
                $entry = WebhookData::object($item, 'media item');
                $media[] = WebhookData::text($entry, 'url', true);
            }
        }
        $providerId = WebhookData::text($payload, 'id', true);
        $metadata = $mediaItems !== [] ? ['media' => $mediaItems] : [];
        if ($eventType) {
            $metadata['event_type'] = $eventType;
        }
        if (isset($payload['record_type'])) {
            $metadata['record_type'] = $payload['record_type'];
        }

        return WebhookProcessingResult::inbound(Driver::Telnyx, $from, $to, $text, $media, $metadata, $providerId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function mapStatusPayload(array $payload, ?string $eventType): WebhookProcessingResult
    {
        $providerId = WebhookData::text($payload, 'id') ?? WebhookData::text($payload, 'message_id', true);
        $rawStatus = $this->extractRawStatus($payload);
        $status = StatusMapper::map(Driver::Telnyx, $rawStatus, $eventType);
        $metadata = [];
        if ($eventType) {
            $metadata['event_type'] = $eventType;
        }
        if ($rawStatus) {
            $metadata['raw_status'] = $rawStatus;
        }
        if (! empty($payload['errors'])) {
            $metadata['errors'] = $payload['errors'];
        }
        $recipients = $this->extractRecipientMetadata($payload);
        if ($recipients) {
            $metadata['recipients'] = $recipients;
        }

        return WebhookProcessingResult::status(Driver::Telnyx, $providerId, $status, $metadata);
    }

    protected function parsePhoneOrFail(mixed $raw, string $field): PhoneNumber
    {
        if (! is_string($raw) || $raw === '') {
            throw new TextoWebhookValidationException("Telnyx payload missing {$field} phone number.");
        }
        try {
            return PhoneNumber::fromString($raw);
        } catch (\Throwable $e) {
            throw new TextoWebhookValidationException("Invalid {$field} phone number supplied: ".$e->getMessage(), 0, $e);
        }
    }

    protected function isInboundEvent(?string $eventType, string $direction): bool
    {
        if ($eventType === 'message.received') {
            return true;
        }

        return $direction === 'inbound';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function extractRawStatus(array $payload): ?string
    {
        if (isset($payload['status']) && is_string($payload['status'])) {
            return $payload['status'];
        }

        $to = $payload['to'] ?? null;
        if (is_array($to)) {
            if (isset($to['status']) && is_string($to['status'])) {
                return $to['status'];
            }
            if (isset($to[0]['status']) && is_string($to[0]['status'])) {
                return $to[0]['status'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, string>>
     */
    protected function extractRecipientMetadata(array $payload): array
    {
        $to = $payload['to'] ?? null;
        if (! is_array($to)) {
            return [];
        }

        $entries = isset($to[0]) && is_array($to[0]) ? $to : [$to];
        $recipients = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $recipient = array_filter([
                'phone_number' => $entry['phone_number'] ?? null,
                'status' => $entry['status'] ?? null,
            ], fn ($value) => $value !== null);
            if ($recipient) {
                $recipients[] = $recipient;
            }
        }

        return $recipients;
    }
}
