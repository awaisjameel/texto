<?php

declare(strict_types=1);

use Awaisjameel\Texto\Contracts\TwilioMessagingApiInterface;
use Awaisjameel\Texto\Drivers\TelnyxSender;
use Awaisjameel\Texto\Drivers\TwilioSender;
use Awaisjameel\Texto\ValueObjects\PhoneNumber;
use Illuminate\Support\Facades\Http;

// Senders must call the provider with the credentials they were constructed with. Boot-time
// singletons (and the Http macros' call-time config reads) previously let one tenant's URL
// pair with another tenant's auth header under per-send driver_config overrides.

it('twilio sender authenticates with its own credentials, not global config', function () {
    config()->set('texto.twilio.account_sid', 'ACGLOBALGLOBALGLOBALGLOBALGLOBAL01');
    config()->set('texto.twilio.auth_token', 'global-token');
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

    $sender = new TwilioSender([
        'account_sid' => 'ACTENANTTENANTTENANTTENANTTENANT01',
        'auth_token' => 'tenant-token',
        'from_number' => '+15550001111',
        'use_conversations' => false,
    ]);
    $sender->send(PhoneNumber::fromString('+15551234567'), 'Hello');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/Accounts/ACTENANTTENANTTENANTTENANTTENANT01/Messages.json')
            && $request->header('Authorization')[0] === 'Basic '.base64_encode('ACTENANTTENANTTENANTTENANTTENANT01:tenant-token');
    });
});

it('telnyx sender authenticates with its own api key, not global config', function () {
    config()->set('texto.telnyx.api_key', 'global-key');
    Http::fake(['api.telnyx.com/*' => Http::response(['data' => ['id' => 'T1', 'to' => [['status' => 'queued']]]], 200)]);

    $sender = new TelnyxSender([
        'api_key' => 'tenant-key',
        'from_number' => '+15550001111',
        'messaging_profile_id' => 'mp-1',
    ]);
    $sender->send(PhoneNumber::fromString('+15551234567'), 'Hello');

    Http::assertSent(fn ($request) => $request->header('Authorization')[0] === 'Bearer tenant-key');
});

it('twilio sender ignores container-bound API adapters', function () {
    app()->instance(TwilioMessagingApiInterface::class, new class implements TwilioMessagingApiInterface
    {
        public function sendMessage(string $to, string $from, ?string $body, array $mediaUrls = [], array $options = []): array
        {
            throw new RuntimeException('The container-bound adapter must not be used.');
        }

        public function fetchMessage(string $messageSid): array
        {
            throw new RuntimeException('The container-bound adapter must not be used.');
        }
    });
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

    $sender = new TwilioSender([
        'account_sid' => 'ACXXXX',
        'auth_token' => 'token',
        'from_number' => '+15550001111',
        'use_conversations' => false,
    ]);
    $result = $sender->send(PhoneNumber::fromString('+15551234567'), 'Hello');

    expect($result->providerMessageId)->toBe('SM1');
});
