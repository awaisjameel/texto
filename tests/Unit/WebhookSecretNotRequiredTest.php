<?php

declare(strict_types=1);

use Awaisjameel\Texto\Models\Message;
use Illuminate\Support\Facades\Http;

// Twilio and Telnyx cannot send custom headers, so their webhook routes must keep working when
// texto.webhook.secret is configured — the routes are authenticated by provider signatures, not
// the X-Texto-Secret middleware. These are regressions for the lockout that guarded them before.
beforeEach(function () {
    config()->set('texto.testing.skip_webhook_validation', true);
    config()->set('texto.webhook.secret', 'configured-but-unsendable-by-providers');
});

it('processes telnyx webhooks without the shared secret header even when a secret is configured', function () {
    $payload = [
        'data' => [
            'event_type' => 'message.received',
            'payload' => [
                'id' => 'no-secret-header-1',
                'direction' => 'inbound',
                'from' => ['phone_number' => '+15555550123'],
                'to' => [['phone_number' => '+10000000000']],
                'text' => 'Hello',
                'media' => [],
            ],
        ],
    ];

    $response = $this->postJson('/texto/webhook/telnyx', $payload);

    expect($response->status())->toBe(200);
    expect(Message::where('provider_message_id', 'no-secret-header-1')->exists())->toBeTrue();
});

it('processes twilio webhooks without the shared secret header even when a secret is configured', function () {
    config()->set('texto.twilio.auth_token', 'token');
    Http::fake();

    $response = $this->post('/texto/webhook/twilio', [
        'MessageStatus' => 'delivered',
        'MessageSid' => 'SM_no_secret_header',
    ]);

    expect($response->status())->toBe(200);
});
