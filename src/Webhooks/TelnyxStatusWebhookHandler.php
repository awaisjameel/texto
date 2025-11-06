<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Webhooks;

use Awaisjameel\Texto\Contracts\WebhookHandlerInterface;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Exceptions\TextoWebhookValidationException;
use Awaisjameel\Texto\Support\StatusMapper;
use Awaisjameel\Texto\ValueObjects\WebhookProcessingResult;
use Illuminate\Http\Request;

class TelnyxStatusWebhookHandler implements WebhookHandlerInterface
{
    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = empty($config) ? (config('texto.telnyx') ?? []) : $config;
    }

    public function handle(Request $request): WebhookProcessingResult
    {
        $payload = $request->json()->all();
        $skip = config('texto.testing.skip_webhook_validation', false) && app()->environment('testing');
        $apiKey = $this->config['api_key'] ?? null;
        if (! $skip && ! $apiKey) {
            throw new TextoWebhookValidationException('Telnyx api_key missing for status webhook validation.');
        }
        // TODO: implement signature verification for Telnyx events when not skipping.

        $data = $payload['data'] ?? [];
        $eventType = $data['event_type'] ?? null;
        $providerId = $data['payload']['id'] ?? null;

        $status = StatusMapper::map(Driver::Telnyx, null, $eventType);

        return WebhookProcessingResult::status(Driver::Telnyx, $providerId, $status, ['event_type' => $eventType]);
    }
}
