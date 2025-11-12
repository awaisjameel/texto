<?php

declare(strict_types=1);

use Awaisjameel\Texto\Exceptions\TelnyxApiAuthException;
use Awaisjameel\Texto\Support\TelnyxMessagingApi;
use Illuminate\Support\Facades\Http;

it('sends a Telnyx message successfully', function () {
    Http::fake([
        'https://api.telnyx.com/v2/messages' => Http::response([
            'data' => [
                'id' => 'msg_123',
                'to' => [['phone_number' => '+15551230000', 'status' => 'accepted']],
                'parts' => 1,
            ],
        ], 200),
    ]);

    $api = new TelnyxMessagingApi('test_key');
    $data = $api->sendMessage('+15551230000', '+15557778888', 'Hello world', [], ['messaging_profile_id' => 'mp_abc']);

    expect($data['id'] ?? null)->toBe('msg_123');
});

it('maps auth error to TelnyxApiAuthException', function () {
    Http::fake([
        'https://api.telnyx.com/v2/messages' => Http::response([
            'errors' => [['code' => '401', 'detail' => 'Unauthorized']],
        ], 401),
    ]);

    $api = new TelnyxMessagingApi('bad_key');
    $api->sendMessage('+15551230000', '+15557778888', 'Hello world');
})->throws(TelnyxApiAuthException::class);
