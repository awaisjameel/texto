<?php

declare(strict_types=1);

use Awaisjameel\Texto\Events\MessageReceived;
use Awaisjameel\Texto\Models\Message;
use Illuminate\Support\Facades\Event;

it('verifies Meta subscriptions without Texto shared-secret authentication', function () {
    config()->set('texto.webhook.secret', 'texto-secret');
    config()->set('texto.whatsapp.verify_token', 'meta-token');

    $response = $this->get('/texto/webhook/whatsapp?hub_mode=subscribe&hub_verify_token=meta-token&hub_challenge=challenge');

    expect($response->status())->toBe(200);
    expect($response->getContent())->toBe('challenge');
});

it('processes signed WhatsApp webhook batches without Texto shared-secret authentication', function () {
    config()->set('texto.webhook.secret', 'texto-secret');
    config()->set('texto.whatsapp.app_secret', 'meta-secret');
    $message = Message::create([
        'direction' => 'sent',
        'driver' => 'whatsapp',
        'to_number' => '+15551234567',
        'body' => 'Outbound',
        'media_urls' => [],
        'status' => 'sent',
        'provider_message_id' => 'wamid.outbound',
        'metadata' => [],
        'sent_at' => now(),
    ]);
    $payload = [
        'entry' => [[
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'metadata' => ['display_phone_number' => '15550003333'],
                    'contacts' => [['wa_id' => '15551234567', 'profile' => ['name' => 'Ada']]],
                    'messages' => [[
                        'from' => '15551234567',
                        'id' => 'wamid.inbound',
                        'type' => 'text',
                        'text' => ['body' => 'Hello'],
                    ]],
                    'statuses' => [['id' => 'wamid.outbound', 'status' => 'delivered']],
                ],
            ]],
        ]],
    ];
    $raw = json_encode($payload, JSON_THROW_ON_ERROR);

    $response = $this->call('POST', '/texto/webhook/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'meta-secret'),
    ], $raw);

    expect($response->status())->toBe(200);
    expect(Message::where('provider_message_id', 'wamid.inbound')->value('body'))->toBe('Hello');
    expect($message->fresh()?->status)->toBe('delivered');
});

it('deduplicates retried WhatsApp inbound webhooks and emits one inbound event', function () {
    config()->set('texto.whatsapp.app_secret', 'meta-secret');
    Event::fake([MessageReceived::class]);
    $payload = [
        'entry' => [[
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'metadata' => ['display_phone_number' => '15550003333'],
                    'contacts' => [['wa_id' => '15551234567']],
                    'messages' => [[
                        'from' => '15551234567',
                        'id' => 'wamid.retried-inbound',
                        'type' => 'text',
                        'text' => ['body' => 'Hello'],
                    ]],
                ],
            ]],
        ]],
    ];
    $raw = json_encode($payload, JSON_THROW_ON_ERROR);
    $headers = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'meta-secret'),
    ];

    $this->call('POST', '/texto/webhook/whatsapp', [], [], [], $headers, $raw)->assertOk();
    $this->call('POST', '/texto/webhook/whatsapp', [], [], [], $headers, $raw)->assertOk();

    expect(Message::where('provider_message_id', 'wamid.retried-inbound')->count())->toBe(1);
    Event::assertDispatchedTimes(MessageReceived::class, 1);
});

it('returns 403 for an unsigned WhatsApp webhook', function () {
    config()->set('texto.whatsapp.app_secret', 'meta-secret');

    $response = $this->postJson('/texto/webhook/whatsapp', ['entry' => []]);

    expect($response->status())->toBe(403);
    expect($response->json('error'))->toBe('webhook_validation_failed');
});
