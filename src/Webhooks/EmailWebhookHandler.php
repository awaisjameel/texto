<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Webhooks;

use Awaisjameel\Texto\Contracts\WebhookHandlerInterface;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Exceptions\TextoWebhookValidationException;
use Awaisjameel\Texto\Support\StatusMapper;
use Awaisjameel\Texto\ValueObjects\EmailAddress;
use Awaisjameel\Texto\ValueObjects\WebhookProcessingResult;
use Illuminate\Http\Request;

/**
 * Provider-agnostic email webhook. Unlike Twilio/Telnyx/Meta there is no single email-provider
 * signature scheme, so this endpoint authenticates with the package's shared secret
 * (texto.webhook.secret) sent either as the X-Texto-Secret header or a `secret` query parameter
 * (for providers that can only call a plain URL). The endpoint refuses to operate without a
 * configured secret.
 *
 * It accepts a normalized JSON payload; point your provider's inbound-parse / event webhook at a
 * tiny transformer (or a provider that supports custom payload templates) that produces:
 *
 *  Inbound message:
 *    {"event":"inbound","message_id":"<provider-id>","from":"a@x.com","to":"b@y.com",
 *     "subject":"...","text":"...","html":"...","attachments":["https://..."]}
 *
 *  Delivery/engagement status for a previously sent message:
 *    {"event":"status","message_id":"<provider-id>","status":"delivered|bounced|opened|...",
 *     "error_code":"...optional..."}
 */
class EmailWebhookHandler implements WebhookHandlerInterface
{
    public function handle(Request $request): WebhookProcessingResult
    {
        $this->assertValidSecret($request);

        $payload = $request->json()->all();

        return match ($payload['event'] ?? null) {
            'inbound' => $this->mapInbound($payload),
            'status' => $this->mapStatus($payload),
            default => throw new TextoWebhookValidationException('Email webhook payload must declare an "event" of "inbound" or "status".'),
        };
    }

    protected function assertValidSecret(Request $request): void
    {
        if (config('texto.testing.skip_webhook_validation', false) && app()->environment('testing')) {
            return;
        }

        $secret = config('texto.webhook.secret');
        if (! is_string($secret) || $secret === '') {
            // Without a secret this endpoint would accept forged inbound mail from anyone.
            throw new TextoWebhookValidationException('Email webhook requires texto.webhook.secret to be configured.');
        }

        $provided = $request->header('X-Texto-Secret') ?? $request->query('secret');
        if (! is_string($provided) || ! hash_equals($secret, $provided)) {
            throw new TextoWebhookValidationException('Email webhook secret missing or invalid.');
        }
    }

    /** @param array<string, mixed> $payload */
    protected function mapInbound(array $payload): WebhookProcessingResult
    {
        $providerMessageId = $payload['message_id'] ?? null;
        if (! is_string($providerMessageId) || $providerMessageId === '') {
            // The provider message id is the inbound idempotency key; refusing here prevents
            // duplicate rows when a provider retries deliveries.
            throw new TextoWebhookValidationException('Email inbound webhook missing message_id.');
        }

        $from = $this->parseEmail($payload['from'] ?? null, 'from');
        $to = $this->parseEmail($payload['to'] ?? null, 'to');

        $text = is_string($payload['text'] ?? null) ? $payload['text'] : null;
        $html = is_string($payload['html'] ?? null) ? $payload['html'] : null;

        $media = [];
        foreach (is_array($payload['attachments'] ?? null) ? $payload['attachments'] : [] as $attachment) {
            if (is_string($attachment) && $attachment !== '') {
                $media[] = $attachment;
            }
        }

        $metadata = array_filter([
            'event_type' => 'inbound',
            'subject' => is_string($payload['subject'] ?? null) ? $payload['subject'] : null,
            'html' => $html,
            'from_display_name' => $from->displayName,
            'headers' => is_array($payload['headers'] ?? null) ? $payload['headers'] : null,
        ], static fn ($item) => $item !== null);

        return WebhookProcessingResult::inbound(
            Driver::Email,
            $from,
            $to,
            // Prefer the plain-text part as the message body; fall back to HTML so body-less
            // multipart mails are not stored empty.
            $text ?? $html,
            $media,
            $metadata,
            $providerMessageId,
        );
    }

    /** @param array<string, mixed> $payload */
    protected function mapStatus(array $payload): WebhookProcessingResult
    {
        $providerMessageId = $payload['message_id'] ?? null;
        if (! is_string($providerMessageId) || $providerMessageId === '') {
            throw new TextoWebhookValidationException('Email status webhook missing message_id.');
        }
        $rawStatus = $payload['status'] ?? null;
        if (! is_string($rawStatus) || $rawStatus === '') {
            throw new TextoWebhookValidationException('Email status webhook missing status.');
        }

        $metadata = array_filter([
            'event_type' => 'status',
            'raw_status' => $rawStatus,
            'error_code' => is_string($payload['error_code'] ?? null) ? $payload['error_code'] : null,
            'reason' => is_string($payload['reason'] ?? null) ? $payload['reason'] : null,
        ], static fn ($item) => $item !== null);

        return WebhookProcessingResult::status(
            Driver::Email,
            $providerMessageId,
            StatusMapper::map(Driver::Email, $rawStatus),
            $metadata,
        );
    }

    protected function parseEmail(mixed $raw, string $field): EmailAddress
    {
        if (! is_string($raw) || trim($raw) === '') {
            throw new TextoWebhookValidationException("Email webhook missing {$field} address.");
        }

        try {
            return EmailAddress::fromString($raw);
        } catch (\Throwable $e) {
            throw new TextoWebhookValidationException("Invalid email webhook {$field} address: ".$e->getMessage(), 0, $e);
        }
    }
}
