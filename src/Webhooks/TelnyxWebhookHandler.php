<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Webhooks;

use Awaisjameel\Texto\Contracts\WebhookHandlerInterface;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Exceptions\TextoWebhookValidationException;
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
        // Telnyx sends JSON payload
        $payload = $request->json()->all();
        $skip = config('texto.testing.skip_webhook_validation', false) && app()->environment('testing');
        if (! $skip) {
            $this->assertValidTelnyxSignature($request, $this->config);
        }

        $data = $payload['data']['payload'] ?? [];
        $fromRaw = $data['from']['phone_number'] ?? null;
        $toRaw = $data['to'][0]['phone_number'] ?? ($data['to']['phone_number'] ?? null);
        $from = $this->parsePhoneOrFail($fromRaw, 'from');
        $to = $this->parsePhoneOrFail($toRaw, 'to');
        $text = $data['text'] ?? null;
        $media = is_array($data['media'] ?? null) ? $data['media'] : [];
        $providerId = $data['id'] ?? null;

        return WebhookProcessingResult::inbound(Driver::Telnyx, $from, $to, $text, $media, [], $providerId);
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
}
