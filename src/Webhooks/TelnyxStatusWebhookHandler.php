<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Webhooks;

use Awaisjameel\Texto\Contracts\WebhookHandlerInterface;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Support\StatusMapper;
use Awaisjameel\Texto\ValueObjects\WebhookProcessingResult;
use Awaisjameel\Texto\Webhooks\Concerns\ValidatesTelnyxSignature;
use Illuminate\Http\Request;

class TelnyxStatusWebhookHandler implements WebhookHandlerInterface
{
    use ValidatesTelnyxSignature;

    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config ?: config('texto.telnyx', []);
    }

    public function handle(Request $request): WebhookProcessingResult
    {
        $payload = $request->json()->all();
        $skip = config('texto.testing.skip_webhook_validation', false) && app()->environment('testing');
        if (! $skip) {
            $this->assertValidTelnyxSignature($request, $this->config);
        }

        $data = $payload['data'] ?? [];
        $eventType = $data['event_type'] ?? null;
        $providerId = $data['payload']['id'] ?? null;

        $status = StatusMapper::map(Driver::Telnyx, null, $eventType);

        return WebhookProcessingResult::status(Driver::Telnyx, $providerId, $status, ['event_type' => $eventType]);
    }
}
