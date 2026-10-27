<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Webhooks;

use Awaisjameel\Texto\Contracts\WebhookHandlerInterface;
use Awaisjameel\Texto\Drivers\TwilioSender;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Enums\MessageStatus;
use Awaisjameel\Texto\Exceptions\TextoWebhookValidationException;
use Awaisjameel\Texto\Support\StatusMapper;
use Awaisjameel\Texto\Support\TwilioSignatureValidator;
use Awaisjameel\Texto\Support\WebhookData;
use Awaisjameel\Texto\ValueObjects\PhoneNumber;
use Awaisjameel\Texto\ValueObjects\WebhookProcessingResult;
use Illuminate\Http\Request;

class TwilioWebhookHandler implements WebhookHandlerInterface
{
    /** @var array<string, mixed> */
    protected array $config;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config = [])
    {
        $this->config = $config ?: config('texto.twilio', []);
    }

    /**
     * Returns null when the event is authentic but intentionally ignored — Conversations
     * fires onMessageAdded for messages this package itself just sent, and storing that
     * echo would create a duplicate inbound row and a spurious MessageReceived event.
     */
    public function handle(Request $request): ?WebhookProcessingResult
    {
        $skipValidation = config('texto.testing.skip_webhook_validation', false) && app()->environment('testing');
        $token = $this->config['auth_token'] ?? null;
        if (! is_string($token) || $token === '') {
            throw new TextoWebhookValidationException('Twilio auth token missing for webhook validation.');
        }
        $body = $request->getContent();
        $params = $request->isJson() ? WebhookData::body($request)
            : ($body !== '' ? TwilioSignatureValidator::formParameters($body) : $request->post());
        if (! $skipValidation) {
            $signature = $request->header('X-Twilio-Signature');
            $url = $request->getSchemeAndHttpHost().$request->getRequestUri();
            if (! is_string($signature) || ! TwilioSignatureValidator::validate($token, $url, $request->isJson() ? $body : $params, $signature)) {
                throw new TextoWebhookValidationException('Invalid Twilio webhook signature.');
            }
        }
        $payload = $params;

        // Status callback (MessageStatus present): handle first to avoid ambiguity with inbound.
        $statusRaw = WebhookData::text($payload, 'MessageStatus');
        $statusMessageSid = WebhookData::text($payload, 'MessageSid');
        if ($statusRaw && $statusMessageSid) {
            $status = StatusMapper::map(Driver::Twilio, $statusRaw, null);

            return WebhookProcessingResult::status(Driver::Twilio, $statusMessageSid, $status, []);
        }
        // Conversations webhook path (EventType present); falls through to classic messaging otherwise.
        $eventType = WebhookData::text($payload, 'EventType');
        if ($eventType) {
            if ($eventType === 'onDeliveryUpdated') {
                $messageSid = WebhookData::text($payload, 'MessageSid', true);
                $conversationSid = WebhookData::text($payload, 'ConversationSid', true);
                $rawStatus = WebhookData::text($payload, 'Status', true);
                $status = match ($rawStatus) {
                    'read' => MessageStatus::Read,
                    'delivered' => MessageStatus::Delivered,
                    'sent' => MessageStatus::Sent,
                    'failed', 'undelivered' => (new TwilioSender($this->config))->fetchStatus($messageSid, $conversationSid),
                    default => throw new TextoWebhookValidationException('Unsupported Twilio delivery receipt status.'),
                };
                if ($status === null) {
                    throw new \RuntimeException('Twilio aggregate delivery status is temporarily unavailable.');
                }

                return WebhookProcessingResult::status(Driver::Twilio, $messageSid, $status, [
                    'conversation_sid' => $conversationSid,
                    'delivery_receipt_sid' => WebhookData::text($payload, 'DeliveryReceiptSid'),
                    'recipient_status' => $rawStatus,
                ]);
            }
            if (! in_array($eventType, ['onMessageAdded', 'onMessageUpdated'])) {
                throw new TextoWebhookValidationException('Unsupported Twilio conversation event type.');
            }
            $authorRaw = ($payload['Author'] ?? null);
            $author = $this->parsePhoneOrFail($authorRaw, 'author');
            $fromNumberConfigured = $this->config['from_number'] ?? null;
            // Infer "to" as our configured from number (the business/system number)
            if ($fromNumberConfigured) {
                try {
                    $to = PhoneNumber::fromString($fromNumberConfigured);
                } catch (\Throwable $e) {
                    throw new TextoWebhookValidationException('Invalid configured Twilio from number: '.$e->getMessage(), 0, $e);
                }
            } else {
                $to = $this->parsePhoneOrFail($authorRaw, 'author');
            }

            if ($fromNumberConfigured && $author->e164 === $to->e164) {
                return null;
            }
            $body = WebhookData::text($payload, 'Body');
            $providerId = WebhookData::text($payload, 'MessageSid', true);
            $media = [];
            $mediaItems = $payload['Media'] ?? [];
            if (is_string($mediaItems)) {
                try {
                    $mediaItems = json_decode($mediaItems, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw new TextoWebhookValidationException('Twilio Media must be a JSON list.');
                }
            }
            $mediaItems = WebhookData::items($mediaItems, 'Media');
            foreach ($mediaItems as $item) {
                $item = WebhookData::object($item, 'Media item');
                $url = WebhookData::text($item, 'Url') ?? WebhookData::text($item, 'url');
                if ($url !== null && $url !== '') {
                    $media[] = $url;
                }
            }

            return WebhookProcessingResult::inbound(Driver::Twilio, $author, $to, $body, $media, ['conversation_sid' => WebhookData::text($payload, 'ConversationSid'), 'media' => $mediaItems], $providerId);
        }

        // Classic Messaging webhook path
        $from = $this->parsePhoneOrFail(($payload['From'] ?? null), 'from');
        $to = $this->parsePhoneOrFail(($payload['To'] ?? null), 'to');
        $body = WebhookData::text($payload, 'Body');
        $media = [];
        $rawCount = $payload['NumMedia'] ?? 0;
        $mediaCount = is_string($rawCount) || is_int($rawCount) ? filter_var($rawCount, FILTER_VALIDATE_INT) : false;
        if ($mediaCount === false || $mediaCount < 0 || $mediaCount > 10) {
            throw new TextoWebhookValidationException('Twilio NumMedia must be between 0 and 10.');
        }
        for ($i = 0; $i < $mediaCount; $i++) {
            $mediaUrl = WebhookData::text($payload, 'MediaUrl'.$i, true);
            if ($mediaUrl) {
                $media[] = $mediaUrl;
            }
        }
        $providerId = WebhookData::text($payload, 'MessageSid', true);

        return WebhookProcessingResult::inbound(Driver::Twilio, $from, $to, $body, $media, [], $providerId);
    }

    protected function parsePhoneOrFail(mixed $raw, string $field): PhoneNumber
    {
        if (! is_string($raw) || $raw === '') {
            throw new TextoWebhookValidationException("Twilio payload missing {$field} phone number.");
        }
        try {
            return PhoneNumber::fromString($raw);
        } catch (\Throwable $e) {
            throw new TextoWebhookValidationException("Invalid {$field} phone number supplied: ".$e->getMessage(), 0, $e);
        }
    }
}
