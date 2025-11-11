<?php

use Awaisjameel\Texto\Support\TwilioMessagingApi;
use Illuminate\Support\Facades\Http;

it('sends SMS successfully', function () {
    Http::fake([
        'api.twilio.com/2010-04-01/Accounts/*/Messages.json' => Http::response([
            'sid' => 'SM123',
            'status' => 'sent',
        ], 201),
    ]);

    $api = new TwilioMessagingApi('ACXXXX', 'token');
    $resp = $api->sendMessage('+15551234567', '+15550001111', 'Hi');

    expect($resp['sid'])->toBe('SM123');
});

it('handles error response', function () {
    Http::fake([
        'api.twilio.com/2010-04-01/Accounts/*/Messages.json' => Http::response([
            'code' => 30001,
            'message' => 'Queue overflow',
        ], 400),
    ]);
    $api = new TwilioMessagingApi('ACXXXX', 'token');
    $api->sendMessage('+15551234567', '+15550001111', 'Hi');
})->throws(\Awaisjameel\Texto\Exceptions\TwilioApiValidationException::class);
