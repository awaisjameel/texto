<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Webhooks;

use Awaisjameel\Texto\Contracts\WebhookHandlerInterface;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Exceptions\TextoWebhookValidationException;
use Awaisjameel\Texto\Support\TwilioSignatureValidator;
use Awaisjameel\Texto\ValueObjects\PhoneNumber;
use Awaisjameel\Texto\ValueObjects\WebhookProcessingResult;
use Illuminate\Http\Request;

class TwilioWebhookHandler implements WebhookHandlerInterface
{
    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config ?: config('texto.twilio', []);
    }

    public function handle(Request $request): WebhookProcessingResult
    {
        $skipValidation = config('texto.testing.skip_webhook_validation', false) && app()->environment('testing');
        $token = $this->config['auth_token'] ?? null;
        if (! $token) {
            throw new TextoWebhookValidationException('Twilio auth token missing for webhook validation.');
        }
        if (! $skipValidation) {
            $signature = $request->header('X-Twilio-Signature');
            $url = $request->fullUrl();
            $params = $request->all();
            if (! $signature || ! TwilioSignatureValidator::validate($token, $url, $params, $signature)) {
                throw new TextoWebhookValidationException('Invalid Twilio webhook signature.');
            }
        }

        // STATUS CALLBACK (MessageStatus present) -> treat first to avoid ambiguity
        $statusRaw = $request->input('MessageStatus');
        $statusMessageSid = $request->input('MessageSid');
        if ($statusRaw && $statusMessageSid) {
            $status = \Awaisjameel\Texto\Support\StatusMapper::map(Driver::Twilio, $statusRaw, null);

            return WebhookProcessingResult::status(Driver::Twilio, $statusMessageSid, $status, []);
        }
        // Conversations webhook path (EventType present) fallback to classic messaging otherwise
        $eventType = $request->input('EventType');
        if ($eventType) {
            if (! in_array($eventType, ['onMessageAdded', 'onMessageUpdated'])) {
                // Ignore unrelated conversation events by returning a minimal received placeholder (could also throw)
                throw new TextoWebhookValidationException('Unsupported Twilio conversation event type.');
            }
            $authorRaw = $request->input('Author');
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
            $body = $request->input('Body');
            $providerId = $request->input('MessageSid');
            $media = [];
            $mediaItems = $request->input('Media'); // Conversations may send structured Media array
            if (is_array($mediaItems)) {
                foreach ($mediaItems as $item) {
                    if (is_array($item)) {
                        $url = $item['Url'] ?? null; // sometimes temporary URL
                        if ($url) {
                            $media[] = $url;
                        }
                    }
                }
            }

            return WebhookProcessingResult::inbound(Driver::Twilio, $author, $to, $body, $media, ['conversation_sid' => $request->input('ConversationSid')], $providerId);
        }

        // Classic Messaging webhook path
        $from = $this->parsePhoneOrFail($request->input('From'), 'from');
        $to = $this->parsePhoneOrFail($request->input('To'), 'to');
        $body = $request->input('Body');
        $media = [];
        $mediaCount = (int) $request->input('NumMedia', 0);
        for ($i = 0; $i < $mediaCount; $i++) {
            $mediaUrl = $request->input('MediaUrl'.$i);
            if ($mediaUrl) {
                $media[] = $mediaUrl;
            }
        }
        $providerId = $request->input('MessageSid');

        return WebhookProcessingResult::inbound(Driver::Twilio, $from, $to, $body, $media, [], $providerId);
    }

    protected function parsePhoneOrFail(?string $raw, string $field): PhoneNumber
    {
        if (! $raw) {
            throw new TextoWebhookValidationException("Twilio payload missing {$field} phone number.");
        }
        try {
            return PhoneNumber::fromString($raw);
        } catch (\Throwable $e) {
            throw new TextoWebhookValidationException("Invalid {$field} phone number supplied: ".$e->getMessage(), 0, $e);
        }
    }
}
