<?php

use Awaisjameel\Texto\Drivers\TwilioSender;
use Awaisjameel\Texto\ValueObjects\PhoneNumber;
use Illuminate\Support\Facades\Http;

it('sends conversation message with proper form keys', function () {
    $config = [
        'account_sid' => 'ACXXXX',
        'auth_token' => 'token',
        'from_number' => '+15550001111',
        'use_conversations' => true,
    ];

    Http::fake([
        'conversations.twilio.com/v1/Conversations' => Http::response(['sid' => 'CH123'], 201),
        'conversations.twilio.com/v1/Conversations/CH123/Participants' => Http::response(['sid' => 'MB123'], 201),
        'conversations.twilio.com/v1/Conversations/CH123/Messages' => Http::response(['sid' => 'IM999', 'status' => 'sent'], 201),
        'conversations.twilio.com/v1/Conversations/CH123/Webhooks' => Http::response(['sid' => 'WH123'], 201),
    ]);

    $sender = new TwilioSender($config);
    $to = PhoneNumber::fromString('+15551234567');
    $result = $sender->send($to, 'Hello convo');

    expect($result->providerMessageId)->toBe('IM999');

    Http::assertSent(function ($request) {
        if (str_contains($request->url(), '/Messages')) {
            // Form body encoded
            $body = $request->body();
            return str_contains($body, 'Author=%2B15550001111') && str_contains($body, 'Body=Hello%20convo');
        }
        return true;
    });
});
