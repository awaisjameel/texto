<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Drivers;

use Awaisjameel\Texto\Contracts\AddressInterface;
use Awaisjameel\Texto\Contracts\MessageSenderInterface;
use Awaisjameel\Texto\Contracts\WhatsappApiInterface;
use Awaisjameel\Texto\Enums\Direction;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Exceptions\TextoSendFailedException;
use Awaisjameel\Texto\Exceptions\WhatsappApiAuthException;
use Awaisjameel\Texto\Exceptions\WhatsappApiException;
use Awaisjameel\Texto\Exceptions\WhatsappApiRateLimitException;
use Awaisjameel\Texto\Exceptions\WhatsappApiValidationException;
use Awaisjameel\Texto\Support\Retry;
use Awaisjameel\Texto\Support\StatusMapper;
use Awaisjameel\Texto\Support\WhatsappApi;
use Awaisjameel\Texto\ValueObjects\PhoneNumber;
use Awaisjameel\Texto\ValueObjects\SentMessageResult;
use Illuminate\Support\Facades\Log;

class WhatsappSender implements MessageSenderInterface
{
    use Concerns\ExpectsPhoneNumbers;

    protected WhatsappApiInterface $api;

    public function __construct(protected array $config, ?WhatsappApiInterface $api = null)
    {
        $token = $this->config['access_token'] ?? null;
        $phoneNumberId = $this->config['phone_number_id'] ?? null;
        if (! $token || ! $phoneNumberId) {
            throw new TextoSendFailedException('WhatsApp access_token or phone_number_id missing.');
        }

        // Do not resolve the globally-bound API adapter here: callers can override credentials
        // per send, and a singleton would silently send with another tenant's credentials.
        // Tests and custom integrations can still inject an adapter explicitly.
        $this->api = $api ?? new WhatsappApi((string) $token, (string) $phoneNumberId);
    }

    /** @param array<int, mixed> $mediaUrls */
    public function send(AddressInterface $to, string $body, ?AddressInterface $from = null, array $mediaUrls = [], array $metadata = []): SentMessageResult
    {
        $to = $this->assertPhoneNumber($to, 'WhatsApp', 'to');
        $from = $from !== null ? $this->assertPhoneNumber($from, 'WhatsApp', 'from') : null;
        $payload = $this->buildPayload($to, $body, $mediaUrls, $metadata);

        try {
            $response = Retry::exponential(
                fn (): array => $this->api->sendMessage($payload),
                (int) config('texto.retry.max_attempts', 3),
                (int) config('texto.retry.backoff_start_ms', 200),
                fn (\Throwable $error): bool => $this->shouldRetry($error),
            );
        } catch (WhatsappApiException $e) {
            Log::error('Texto WhatsApp API send failed', [
                'error' => $e->getMessage(),
                'status' => $e->status,
                'graph_code' => $e->graphCode,
                'graph_subcode' => $e->graphSubcode,
                'context' => $e->context,
            ]);
            $code = $e->graphCode ? " [Graph code {$e->graphCode}]" : '';
            throw new TextoSendFailedException('WhatsApp API send failed'.$code.': '.$e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            Log::error('Texto WhatsApp send failed', ['error' => $e->getMessage()]);
            throw new TextoSendFailedException('WhatsApp send failed: '.$e->getMessage(), 0, $e);
        }

        $contact = $response['contacts'][0] ?? [];
        $message = $response['messages'][0] ?? [];
        $augmentedMetadata = $metadata + array_filter([
            'whatsapp_wa_id' => is_array($contact) ? ($contact['wa_id'] ?? null) : null,
            'whatsapp_message_status' => is_array($message) ? ($message['message_status'] ?? null) : null,
        ], static fn ($value) => $value !== null);
        $fromNumber = $from ? $from->e164 : ($this->config['from_number'] ?? null);
        $rawStatus = is_array($message) && is_string($message['message_status'] ?? null)
            ? $message['message_status']
            : null;

        return new SentMessageResult(
            Driver::Whatsapp,
            Direction::Sent,
            $to,
            $fromNumber ? PhoneNumber::fromString((string) $fromNumber) : null,
            $body,
            $mediaUrls,
            $augmentedMetadata,
            StatusMapper::map(Driver::Whatsapp, $rawStatus),
            is_array($message) && isset($message['id']) ? (string) $message['id'] : null,
        );
    }

    /**
     * @param  array<int, mixed>  $mediaUrls
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function buildPayload(PhoneNumber $to, string $body, array $mediaUrls, array $metadata): array
    {
        $base = ['messaging_product' => 'whatsapp', 'to' => ltrim($to->e164, '+')];
        $template = $metadata['template'] ?? null;
        if (is_array($template)) {
            $name = $template['name'] ?? null;
            if (! is_string($name) || $name === '') {
                throw new TextoSendFailedException('WhatsApp template metadata requires a template name.');
            }

            return [
                ...$base,
                'type' => 'template',
                'template' => [
                    'name' => $name,
                    'language' => ['code' => is_string($template['language'] ?? null) ? $template['language'] : 'en_US'],
                    'components' => is_array($template['components'] ?? null) ? $template['components'] : [],
                ],
            ];
        }

        if ($mediaUrls === []) {
            if ($body === '') {
                throw new TextoSendFailedException('WhatsApp requires a message body, media URL, or template.');
            }

            return [
                ...$base,
                'type' => 'text',
                'text' => [
                    'body' => $body,
                    'preview_url' => (bool) ($metadata['preview_url'] ?? false),
                ],
            ];
        }

        if (count($mediaUrls) !== 1) {
            throw new TextoSendFailedException('WhatsApp accepts one media URL per send; send each attachment separately.');
        }

        $url = $mediaUrls[0];
        if (! is_string($url) || $url === '') {
            throw new TextoSendFailedException('WhatsApp media URL must be a non-empty string.');
        }
        $type = $this->mediaType($url);
        if (in_array($type, ['audio', 'sticker'], true) && $body !== '') {
            throw new TextoSendFailedException("WhatsApp {$type} messages cannot include a caption; send the text separately.");
        }
        $media = ['link' => $url];
        if ($body !== '') {
            $media['caption'] = $body;
        }

        return [...$base, 'type' => $type, $type => $media];
    }

    private function mediaType(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? $url);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        // Per Meta's supported media types: WebP is accepted only as a sticker, never as an image.
        return match ($extension) {
            'jpg', 'jpeg', 'png' => 'image',
            'webp' => 'sticker',
            'mp4', '3gp' => 'video',
            'mp3', 'aac', 'ogg', 'amr', 'm4a' => 'audio',
            default => 'document',
        };
    }

    private function shouldRetry(\Throwable $error): bool
    {
        if ($error instanceof WhatsappApiAuthException || $error instanceof WhatsappApiValidationException) {
            return false;
        }

        // The Messages endpoint does not offer an idempotency key. A connection failure or a 5xx
        // can therefore be ambiguous: Meta may have accepted the original request even though we
        // did not receive its response. Retrying it would send the customer a duplicate message.
        // A rate-limit response is the one safe automatic retry because the request was rejected
        // before a message could be accepted.
        return $error instanceof WhatsappApiRateLimitException;
    }
}
