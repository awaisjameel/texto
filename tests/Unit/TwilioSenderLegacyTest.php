<?php

declare(strict_types=1);

use Awaisjameel\Texto\Drivers\TwilioSender;
use Awaisjameel\Texto\ValueObjects\PhoneNumber;
use Illuminate\Support\Facades\Http;

function legacySenderConfig(): array
{
    return [
        'account_sid' => 'ACXXXX',
        'auth_token' => 'token',
        'from_number' => '+15550001111',
        'use_conversations' => false,
    ];
}

it('passes metadata webhook_url as StatusCallback on legacy sends', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

    $sender = new TwilioSender(legacySenderConfig());
    $sender->send(PhoneNumber::fromString('+15551234567'), 'Hello', null, [], [
        'webhook_url' => 'https://app.example/texto/webhook/twilio',
    ]);

    Http::assertSent(fn ($request) => str_contains(
        $request->body(),
        'StatusCallback='.rawurlencode('https://app.example/texto/webhook/twilio'),
    ));
});

it('omits StatusCallback when no webhook_url metadata is given', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

    $sender = new TwilioSender(legacySenderConfig());
    $sender->send(PhoneNumber::fromString('+15551234567'), 'Hello');

    Http::assertSent(fn ($request) => ! str_contains($request->body(), 'StatusCallback'));
});
