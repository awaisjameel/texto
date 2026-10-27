<?php

declare(strict_types=1);

use Awaisjameel\Texto\Drivers\TwilioSender;
use Awaisjameel\Texto\Enums\MessageStatus;
use Awaisjameel\Texto\Webhooks\TwilioWebhookHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

it('accepts any successful conversation recipient and fails only when all fail', function (array $delivery, MessageStatus $expected): void {
    Http::fake(['conversations.twilio.com/*' => Http::response(['delivery' => $delivery])]);
    $sender = new TwilioSender(['account_sid' => 'AC1', 'auth_token' => 'token', 'use_conversations' => false]);
    expect($sender->fetchStatus('IM1', 'CH1'))->toBe($expected);
    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.twilio.com'));
})->with([
    'read with failures' => [['total' => 2, 'read' => 'some', 'delivered' => 'some', 'failed' => 'some'], MessageStatus::Read],
    'delivered with failures' => [['total' => 2, 'delivered' => 'some', 'failed' => 'some'], MessageStatus::Delivered],
    'partial failure pending' => [['total' => 2, 'failed' => 'some'], MessageStatus::Sent],
    'all failed' => [['total' => 2, 'failed' => 'all'], MessageStatus::Failed],
    'all undelivered' => [['total' => 2, 'undelivered' => 'all'], MessageStatus::Undelivered],
]);

it('resolves mixed failure aggregates through every receipt page', function (array $statuses, MessageStatus $expected): void {
    $receipts = array_map(fn ($status, $index) => ['sid' => 'DY'.$index, 'status' => $status], $statuses, array_keys($statuses));
    Http::fake([
        'conversations.twilio.com/v1/Conversations/CH1/Messages/IM1' => Http::response(['delivery' => ['total' => count($statuses), 'failed' => 'some', 'undelivered' => 'some']]),
        'conversations.twilio.com/v1/Conversations/CH1/Messages/IM1/Receipts' => Http::response(['delivery_receipts' => [$receipts[0]], 'meta' => ['next_page_url' => 'https://conversations.twilio.com/v1/Conversations/CH1/Messages/IM1/Receipts?PageToken=next']]),
        'conversations.twilio.com/v1/Conversations/CH1/Messages/IM1/Receipts?PageToken=next' => Http::response(['delivery_receipts' => array_slice($receipts, 1)]),
    ]);
    expect((new TwilioSender(['account_sid' => 'AC1', 'auth_token' => 'token']))->fetchStatus('IM1', 'CH1'))->toBe($expected);
    Http::assertSentCount(3);
})->with([
    'mixed terminal failures' => [['failed', 'undelivered'], MessageStatus::Failed],
    'one pending' => [['failed', 'undelivered', null], MessageStatus::Sent],
    'one delivered' => [['failed', 'delivered'], MessageStatus::Delivered],
    'read wins independently of receipt order' => [['delivered', 'read'], MessageStatus::Read],
]);

it('never looks up a conversation message in the classic Messages API after a failure or missing context', function (): void {
    Http::fake(['*' => Http::response([], 503)]);
    $sender = new TwilioSender(['account_sid' => 'AC1', 'auth_token' => 'token']);
    expect($sender->fetchStatus('IM1', 'CH1'))->toBeNull()->and($sender->fetchStatus('IM1'))->toBeNull();
    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.twilio.com'));
});

it('authenticates delivery callbacks and does not mark a whole conversation failed for one failed recipient', function (): void {
    Http::fake(['conversations.twilio.com/*' => Http::response(['delivery' => ['total' => 2, 'failed' => 'some', 'delivered' => 'some']])]);
    $payload = ['EventType' => 'onDeliveryUpdated', 'MessageSid' => 'IM1', 'ConversationSid' => 'CH1', 'DeliveryReceiptSid' => 'DY1', 'Status' => 'failed'];
    $url = 'https://app.example/texto/webhook/twilio';
    $signature = base64_encode(hash_hmac('sha1', $url.implode('', array_map(fn ($key) => $key.$payload[$key], (function () use ($payload) {
        $keys = array_keys($payload);
        sort($keys);

        return $keys;
    })())), 'token', true));
    $request = Request::create($url, 'POST', $payload, server: ['HTTP_X_TWILIO_SIGNATURE' => $signature]);
    $result = (new TwilioWebhookHandler(['account_sid' => 'AC1', 'auth_token' => 'token']))->handle($request);
    expect($result->status)->toBe(MessageStatus::Delivered)->and($result->providerMessageId)->toBe('IM1')
        ->and($result->metadata['recipient_status'])->toBe('failed');
});
