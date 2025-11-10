<?php

declare(strict_types=1);

use Awaisjameel\Texto\Models\Message;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('texto.testing.skip_webhook_validation', true);
    config()->set('texto.webhook.secret', 'shared-secret');
});

it('updates status via telnyx webhook endpoint', function () {
    $message = Message::create([
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
                'id' => $message->provider_message_id,
                'direction' => 'outbound',
                'to' => [
                    [
                        'phone_number' => $message->to_number,
                        'status' => 'delivered',
                    ],
                ],
            ],
        ],
    ];

    $response = $this->postJson('/texto/webhook/telnyx', $payload, ['X-Texto-Secret' => 'shared-secret']);
    expect($response->status())->toBe(200);

    $message->refresh();
    expect($message->status)->toBe('delivered');
});

it('stores inbound messages via telnyx webhook endpoint', function () {
    $inboundId = 'incoming-'.Str::random(6);
    $payload = [
        'data' => [
            'event_type' => 'message.received',
            'payload' => [
                'id' => $inboundId,
                'direction' => 'inbound',
                'from' => ['phone_number' => '+15555550123'],
                'to' => [
                    ['phone_number' => '+10000000000'],
                ],
                'text' => 'Hey there',
                'media' => [],
            ],
        ],
    ];

    $response = $this->postJson('/texto/webhook/telnyx', $payload, ['X-Texto-Secret' => 'shared-secret']);
    expect($response->status())->toBe(200);

    $stored = Message::where('provider_message_id', $inboundId)->first();
    expect($stored)->not->toBeNull();
    expect($stored->direction)->toBe('received');
    expect($stored->body)->toBe('Hey there');
    expect($stored->from_number)->toBe('+15555550123');
    expect($stored->to_number)->toBe('+10000000000');
});
