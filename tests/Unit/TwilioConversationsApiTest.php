<?php

use Awaisjameel\Texto\Support\TwilioConversationsApi;
use Illuminate\Support\Facades\Http;

it('creates conversation and sends message', function () {
    Http::fake([
        'conversations.twilio.com/v1/Conversations' => Http::response(['sid' => 'CH123'], 201),
        'conversations.twilio.com/v1/Conversations/CH123/Participants' => Http::response(['sid' => 'MB123'], 201),
        'conversations.twilio.com/v1/Conversations/CH123/Messages' => Http::response(['sid' => 'IM123', 'status' => 'sent'], 201),
    ]);

    $api = new TwilioConversationsApi('ACXXXX', 'token');
    $conv = $api->createConversation('Test');
    expect($conv['sid'])->toBe('CH123');
    $api->addParticipant('CH123', '+15551234567', '+15550001111');
    $msg = $api->sendConversationMessage('CH123', ['Body' => 'Hello']);
    expect($msg['sid'])->toBe('IM123');
});

it('handles participant duplicate', function () {
    Http::fake([
        'conversations.twilio.com/v1/Conversations' => Http::response(['sid' => 'CH999'], 201),
        'conversations.twilio.com/v1/Conversations/CH999/Participants' => Http::response([
            'code' => 50416,
            'message' => 'Duplicate participant'
        ], 400),
    ]);

    $api = new TwilioConversationsApi('ACXXXX', 'token');
    $api->createConversation('Dup');
    $api->addParticipant('CH999', '+15551230000', '+15550001111');
})->throws(\Awaisjameel\Texto\Exceptions\TwilioApiValidationException::class);
