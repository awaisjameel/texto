<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Webhooks;

use Awaisjameel\Texto\Contracts\WebhookHandlerInterface;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Exceptions\TextoWebhookValidationException;
use Awaisjameel\Texto\Support\StatusMapper;
use Awaisjameel\Texto\ValueObjects\PhoneNumber;
use Awaisjameel\Texto\ValueObjects\WebhookProcessingResult;
use Awaisjameel\Texto\Webhooks\Concerns\ValidatesTelnyxSignature;
use Illuminate\Http\Request;

class TelnyxWebhookHandler implements WebhookHandlerInterface
{
    use ValidatesTelnyxSignature;

    protected array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? config('texto.telnyx', []);
    }

    public function handle(Request $request): WebhookProcessingResult
    {
        $payload = $request->json()->all();
        $skip = config('texto.testing.skip_webhook_validation', false) && app()->environment('testing');
        if (! $skip) {
            $this->assertValidTelnyxSignature($request, $this->config);
        }

        $data = $payload['data'] ?? [];
        $eventType = isset($data['event_type']) ? strtolower((string) $data['event_type']) : null;
        $messagePayload = $data['payload'] ?? null;
        if (! is_array($messagePayload)) {
            throw new TextoWebhookValidationException('Telnyx payload missing message data.');
        }

        $direction = strtolower((string) ($messagePayload['direction'] ?? ''));
        $isInbound = $this->isInboundEvent($eventType, $direction);

        return $isInbound
            ? $this->mapInboundPayload($messagePayload, $eventType)
            : $this->mapStatusPayload($messagePayload, $eventType);
    }

    protected function mapInboundPayload(array $payload, ?string $eventType): WebhookProcessingResult
    {
        $fromRaw = $payload['from']['phone_number'] ?? null;
        $toRaw = $payload['to'][0]['phone_number'] ?? ($payload['to']['phone_number'] ?? null);
        $from = $this->parsePhoneOrFail($fromRaw, 'from');
        $to = $this->parsePhoneOrFail($toRaw, 'to');
        $text = $payload['text'] ?? null;
        $media = is_array($payload['media'] ?? null) ? $payload['media'] : [];
        $providerId = $payload['id'] ?? null;
        $metadata = [];
        if ($eventType) {
            $metadata['event_type'] = $eventType;
        }
        if (isset($payload['record_type'])) {
            $metadata['record_type'] = $payload['record_type'];
        }

        return WebhookProcessingResult::inbound(Driver::Telnyx, $from, $to, $text, $media, $metadata, $providerId);
    }

    protected function mapStatusPayload(array $payload, ?string $eventType): WebhookProcessingResult
    {
        $providerId = $payload['id'] ?? ($payload['message_id'] ?? null);
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

    protected function parsePhoneOrFail(?string $raw, string $field): PhoneNumber
    {
        if (! $raw) {
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

    /** @return array<int, array<string, string>> */
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
