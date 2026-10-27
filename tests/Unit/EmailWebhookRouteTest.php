<?php

declare(strict_types=1);

use Awaisjameel\Texto\Events\MessageReceived;
use Awaisjameel\Texto\Events\MessageStatusUpdated;
use Awaisjameel\Texto\Models\Message;
use Illuminate\Support\Facades\Event;

function postEmailWebhook($test, array $payload, array $server = [])
{
    $raw = json_encode($payload, JSON_THROW_ON_ERROR);

    return $test->call('POST', '/texto/webhook/email', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ] + $server, $raw);
}

afterEach(function () {
    config()->set('texto.webhook.secret', null);
});

it('stores an inbound email and fires MessageReceived', function () {
    config()->set('texto.webhook.secret', 'shh');
    Event::fake([MessageReceived::class]);

    $response = postEmailWebhook($this, [
        'event' => 'inbound',
        'message_id' => '<inbound-1@mail.example.com>',
        'from' => 'Jane <jane@example.com>',
        'to' => 'inbox@myapp.com',
        'subject' => 'Hello',
        'text' => 'Inbound body',
    ], ['HTTP_X_TEXTO_SECRET' => 'shh']);

    expect($response->status())->toBe(200);
    $record = Message::first();
    expect($record->direction)->toBe('received');
    expect($record->driver)->toBe('email');
    expect($record->from_number)->toBe('jane@example.com');
    expect($record->to_number)->toBe('inbox@myapp.com');
    expect($record->body)->toBe('Inbound body');
    expect($record->status)->toBe('received');
    Event::assertDispatched(MessageReceived::class);
});

it('is idempotent for redelivered inbound emails', function () {
    config()->set('texto.webhook.secret', 'shh');
    Event::fake([MessageReceived::class]);

    $payload = [
        'event' => 'inbound',
        'message_id' => '<dup-1@mail.example.com>',
        'from' => 'jane@example.com',
        'to' => 'inbox@myapp.com',
        'text' => 'Same email twice',
    ];
    postEmailWebhook($this, $payload, ['HTTP_X_TEXTO_SECRET' => 'shh']);
    postEmailWebhook($this, $payload, ['HTTP_X_TEXTO_SECRET' => 'shh']);

    expect(Message::count())->toBe(1);
    Event::assertDispatchedTimes(MessageReceived::class, 1);
});

it('advances a sent email to delivered on a status event', function () {
    config()->set('texto.webhook.secret', 'shh');
    Event::fake([MessageStatusUpdated::class]);

    $message = Message::create([
        'direction' => 'sent',
        'driver' => 'email',
        'from_number' => 'noreply@myapp.com',
        'to_number' => 'jane@example.com',
        'body' => 'Outbound',
        'media_urls' => [],
        'status' => 'sent',
        'provider_message_id' => '<outbound-1@mail.example.com>',
        'metadata' => [],
        'sent_at' => now(),
    ]);

    $response = postEmailWebhook($this, [
        'event' => 'status',
        'message_id' => '<outbound-1@mail.example.com>',
        'status' => 'delivered',
    ], ['HTTP_X_TEXTO_SECRET' => 'shh']);

    expect($response->status())->toBe(200);
    expect($message->fresh()?->status)->toBe('delivered');
    Event::assertDispatched(MessageStatusUpdated::class);
});

it('does not regress a delivered email when a late sent event arrives', function () {
    config()->set('texto.webhook.secret', 'shh');
    Event::fake([MessageStatusUpdated::class]);

    $message = Message::create([
        'direction' => 'sent',
        'driver' => 'email',
        'to_number' => 'jane@example.com',
        'body' => 'Outbound',
        'media_urls' => [],
        'status' => 'delivered',
        'provider_message_id' => '<outbound-2@mail.example.com>',
        'metadata' => [],
        'sent_at' => now(),
    ]);

    postEmailWebhook($this, [
        'event' => 'status',
        'message_id' => '<outbound-2@mail.example.com>',
        'status' => 'sent',
    ], ['HTTP_X_TEXTO_SECRET' => 'shh']);

    expect($message->fresh()?->status)->toBe('delivered');
    Event::assertNotDispatched(MessageStatusUpdated::class);
});

it('rejects requests without the shared secret', function () {
    config()->set('texto.webhook.secret', 'shh');

    $response = postEmailWebhook($this, [
        'event' => 'status',
        'message_id' => 'x',
        'status' => 'delivered',
    ]);

    expect($response->status())->toBe(403);
});

it('rejects all requests when no secret is configured', function () {
    config()->set('texto.webhook.secret', null);

    $response = postEmailWebhook($this, [
        'event' => 'status',
        'message_id' => 'x',
        'status' => 'delivered',
    ], ['HTTP_X_TEXTO_SECRET' => 'anything']);

    expect($response->status())->toBe(403);
});
