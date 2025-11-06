<?php

declare(strict_types=1);

use Awaisjameel\Texto\Models\Message;
use Illuminate\Support\Str;

it('updates status via telnyx status webhook', function () {
    config()->set('texto.testing.skip_webhook_validation', true);
    config()->set('texto.telnyx.api_key', 'test');
    // Seed a sent message
    $msg = Message::create([
        'direction' => 'sent',
        'driver' => 'telnyx',
        'from_number' => '+10000000000',
        'to_number' => '+15555550123',
        'body' => 'Hello',
        'media_urls' => [],
        'status' => 'sent',
        'provider_message_id' => 'telnyx-'.Str::random(8),
        'metadata' => [],
        'sent_at' => now(),
    ]);

    $payload = [
        'data' => [
            'event_type' => 'message.delivered',
            'payload' => [
                'id' => $msg->provider_message_id,
            ],
        ],
    ];

    $response = $this->postJson('/texto/webhook/telnyx/status', $payload, ['X-Texto-Secret' => config('texto.webhook.secret')]);
    expect($response->status())->toBe(200);

    $msg->refresh();
    expect($msg->status)->toBe('delivered');
});
