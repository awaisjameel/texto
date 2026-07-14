<?php

declare(strict_types=1);

use Awaisjameel\Texto\Models\Message;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('texto.testing.skip_webhook_validation', true);
    config()->set('texto.webhook.secret', 'shared-secret');
});

function telnyxMessage(string $status = 'sent'): Message
{
    return Message::create([
        'direction' => 'sent',
        'driver' => 'telnyx',
        'from_number' => '+10000000000',
        'to_number' => '+15555550123',
        'body' => 'Hello',
        'media_urls' => [],
        'status' => $status,
        'provider_message_id' => 'telnyx-'.Str::random(8),
        'metadata' => [],
        'sent_at' => now(),
    ]);
}

function telnyxFinalizedPayload(Message $message, string $recipientStatus): array
{
    return [
        'data' => [
            'event_type' => 'message.finalized',
            'payload' => [
                'id' => $message->provider_message_id,
                'direction' => 'outbound',
                'to' => [
                    [
                        'phone_number' => $message->to_number,
                        'status' => $recipientStatus,
                    ],
                ],
            ],
        ],
    ];
}

it('marks delivered from a message.finalized webhook', function () {
    $message = telnyxMessage('sent');

    $response = $this->postJson('/texto/webhook/telnyx', telnyxFinalizedPayload($message, 'delivered'), ['X-Texto-Secret' => 'shared-secret']);
    expect($response->status())->toBe(200);

    $message->refresh();
    expect($message->status)->toBe('delivered');
});

it('marks failed from a message.finalized webhook with delivery_failed status', function () {
    $message = telnyxMessage('sent');

    $response = $this->postJson('/texto/webhook/telnyx', telnyxFinalizedPayload($message, 'delivery_failed'), ['X-Texto-Secret' => 'shared-secret']);
    expect($response->status())->toBe(200);

    $message->refresh();
    expect($message->status)->toBe('failed');
});

it('does not regress a delivered message when a late status webhook arrives', function () {
    $message = telnyxMessage('delivered');

    // A late/out-of-order 'sent' DLR must not overwrite the terminal 'delivered' state.
    $response = $this->postJson('/texto/webhook/telnyx', telnyxFinalizedPayload($message, 'sent'), ['X-Texto-Secret' => 'shared-secret']);
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

function signedTelnyxRequest(string $rawPayload, int $timestamp): array
{
    $keypair = sodium_crypto_sign_keypair();
    config()->set('texto.telnyx.webhook_secret', base64_encode(sodium_crypto_sign_publickey($keypair)));
    $signature = base64_encode(sodium_crypto_sign_detached($timestamp.'.'.$rawPayload, sodium_crypto_sign_secretkey($keypair)));

    return [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_TELNYX_SIGNATURE_ED25519' => $signature,
        'HTTP_TELNYX_SIGNATURE_TIMESTAMP' => (string) $timestamp,
    ];
}

it('rejects a correctly signed telnyx webhook whose timestamp is stale (replay protection)', function () {
    config()->set('texto.testing.skip_webhook_validation', false);

    $raw = json_encode(telnyxFinalizedPayload(telnyxMessage('sent'), 'delivered'), JSON_THROW_ON_ERROR);
    $server = signedTelnyxRequest($raw, time() - 3600); // valid signature, one hour old

    $response = $this->call('POST', '/texto/webhook/telnyx', [], [], [], $server, $raw);

    expect($response->status())->toBe(403);
    expect($response->json('error'))->toBe('webhook_validation_failed');
});

it('accepts a correctly signed telnyx webhook with a fresh timestamp', function () {
    config()->set('texto.testing.skip_webhook_validation', false);

    $message = telnyxMessage('sent');
    $raw = json_encode(telnyxFinalizedPayload($message, 'delivered'), JSON_THROW_ON_ERROR);
    $server = signedTelnyxRequest($raw, time());

    $response = $this->call('POST', '/texto/webhook/telnyx', [], [], [], $server, $raw);

    expect($response->status())->toBe(200);
    $message->refresh();
    expect($message->status)->toBe('delivered');
});

it('returns 403 (not 500) when webhook signature validation fails', function () {
    // Enable real validation; the request carries no Telnyx signature headers, so it must be rejected.
    config()->set('texto.testing.skip_webhook_validation', false);
    config()->set('texto.telnyx.webhook_secret', base64_encode(str_repeat("\0", 32)));

    $payload = ['data' => ['event_type' => 'message.received', 'payload' => ['direction' => 'inbound']]];

    $response = $this->postJson('/texto/webhook/telnyx', $payload, ['X-Texto-Secret' => 'shared-secret']);

    expect($response->status())->toBe(403);
    expect($response->json('error'))->toBe('webhook_validation_failed');
});
