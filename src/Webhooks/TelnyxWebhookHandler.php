<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Webhooks;

use Awaisjameel\Texto\Contracts\WebhookHandlerInterface;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Exceptions\TextoWebhookValidationException;
use Awaisjameel\Texto\ValueObjects\PhoneNumber;
use Awaisjameel\Texto\ValueObjects\WebhookProcessingResult;
use Illuminate\Http\Request;

class TelnyxWebhookHandler implements WebhookHandlerInterface
{
    protected array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? config('texto.telnyx', []);
    }

    public function handle(Request $request): WebhookProcessingResult
    {
        // Telnyx sends JSON payload
        $payload = $request->json()->all();
        $apiKey = $this->config['api_key'] ?? null;
        if (! $apiKey) {
            throw new TextoWebhookValidationException('Telnyx api_key missing for webhook validation.');
        }
        // Signature validation (placeholder - implement HMAC verification with Telnyx signature headers)
        // TODO: Implement full Telnyx signature validation.

        $data = $payload['data']['payload'] ?? [];
        $from = PhoneNumber::fromString($data['from']['phone_number'] ?? '');
        $to = PhoneNumber::fromString($data['to'][0]['phone_number'] ?? ($data['to']['phone_number'] ?? ''));
        $text = $data['text'] ?? null;
        $media = $data['media'] ?? [];
        $providerId = $data['id'] ?? null;

        return WebhookProcessingResult::inbound(Driver::Telnyx, $from, $to, $text, $media, [], $providerId);
    }
}
