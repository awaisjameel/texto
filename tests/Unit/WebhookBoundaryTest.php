<?php

declare(strict_types=1);

use Awaisjameel\Texto\Events\MessageReceived;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

it('emits inbound events without accessing the message table when persistence is disabled', function (): void {
    config()->set('texto.store_messages', false);
    config()->set('texto.webhook.secret', 'test-secret');
    Schema::drop('texto_messages');
    Event::fake([MessageReceived::class]);
    $this->postJson('/texto/webhook/email', ['event' => 'inbound', 'message_id' => 'no-storage', 'from' => 'from@example.com', 'to' => 'to@example.com', 'text' => 'body'], ['X-Texto-Secret' => 'test-secret'])->assertOk();
    Event::assertDispatchedTimes(MessageReceived::class, 1);
});

it('rejects array-valued Meta handshake parameters without a server error', function (): void {
    config()->set('texto.whatsapp.verify_token', 'verify-token');
    $this->get('/texto/webhook/whatsapp?hub.mode=subscribe&hub.verify_token[]=verify-token&hub.challenge=challenge')->assertForbidden();
});

it('returns validation errors for malformed signed-provider payload structures', function (): void {
    config()->set('texto.testing.skip_webhook_validation', true);
    $this->postJson('/texto/webhook/whatsapp', ['entry' => [['changes' => [['field' => 'messages', 'value' => ['messages' => 'invalid-list']]]]]])->assertForbidden();
    $this->postJson('/texto/webhook/telnyx', ['data' => ['event_type' => 'message.received', 'payload' => ['from' => ['phone_number' => []]]]])->assertForbidden();
});
