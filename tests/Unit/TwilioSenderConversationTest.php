<?php

use Awaisjameel\Texto\Drivers\TwilioSender;
use Awaisjameel\Texto\Exceptions\TextoSendFailedException;
use Awaisjameel\Texto\ValueObjects\PhoneNumber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function conversationSenderConfig(): array
{
    return [
        'account_sid' => 'ACXXXX',
        'auth_token' => 'token',
        'from_number' => '+15550001111',
        'use_conversations' => true,
        'sms_template_sid' => 'HX111',
        'mms_template_sid' => 'HX222',
    ];
}

function fakeConversationEndpoints(): void
{
    Http::fake([
        'conversations.twilio.com/v1/Conversations' => Http::response(['sid' => 'CH123'], 201),
        'conversations.twilio.com/v1/Conversations/CH123/Participants' => Http::response(['sid' => 'MB123'], 201),
        'conversations.twilio.com/v1/Conversations/CH123/Messages' => Http::response(['sid' => 'IM999', 'status' => 'sent'], 201),
        'conversations.twilio.com/v1/Conversations/CH123/Webhooks' => Http::response(['sid' => 'WH123'], 201),
    ]);
}

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
            $body = $request->body();

            return str_contains($body, 'Author=%2B15550001111') && str_contains($body, 'Body=Hello%20convo');
        }

        return true;
    });
});

it('falls back to a plain body send when the body exceeds template capacity', function () {
    fakeConversationEndpoints();

    $sender = new TwilioSender(conversationSenderConfig());
    $body = str_repeat('a', 501); // one past the 5 x 100 character template capacity
    $result = $sender->send(PhoneNumber::fromString('+15551234567'), $body);

    expect($result->providerMessageId)->toBe('IM999');

    Http::assertSent(function ($request) {
        if (str_contains($request->url(), '/Messages')) {
            return str_contains($request->body(), 'Body=')
                && ! str_contains($request->body(), 'ContentSid');
        }

        return true;
    });
});

it('rejects an over-capacity media body before creating any conversation', function () {
    fakeConversationEndpoints();

    $sender = new TwilioSender(conversationSenderConfig());

    expect(fn () => $sender->send(
        PhoneNumber::fromString('+15551234567'),
        str_repeat('a', 501),
        null,
        ['https://example.com/pic.jpg'],
    ))->toThrow(TextoSendFailedException::class);

    // Rejected before any Twilio call, so no orphaned conversation is left behind.
    Http::assertNothingSent();
});

it('splits template variables on character boundaries for multibyte bodies', function () {
    fakeConversationEndpoints();

    $sender = new TwilioSender(conversationSenderConfig());
    $body = str_repeat('é', 150); // 300 bytes; a byte-based split would corrupt char 100
    $sender->send(PhoneNumber::fromString('+15551234567'), $body);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/Messages')) {
            return true;
        }
        parse_str($request->body(), $params);
        $vars = json_decode($params['ContentVariables'] ?? '', true);

        return is_array($vars)
            && $vars['message_body_1'] === str_repeat('é', 100)
            && $vars['message_body_2'] === str_repeat('é', 50)
            && $vars['message_body_3'] === '';
    });
});

it('sends repeated Configuration.Filters fields when attaching the conversation webhook', function () {
    fakeConversationEndpoints();

    $config = conversationSenderConfig() + ['conversation_webhook_url' => 'https://app.example/texto/webhook/twilio'];
    (new TwilioSender($config))->send(PhoneNumber::fromString('+15551234567'), 'Hello');

    Http::assertSent(function ($request) {
        if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/Webhooks')) {
            return true;
        }
        $body = $request->body();

        // Twilio array parameters repeat per item; a comma-joined value is silently ignored.
        return str_contains($body, 'Configuration.Filters=onMessageAdded&Configuration.Filters=onMessageUpdated')
            && ! str_contains($body, rawurlencode('onMessageAdded,onMessageUpdated'));
    });
});

it('reuses the cached conversation on repeat sends to the same recipient', function () {
    fakeConversationEndpoints();

    $sender = new TwilioSender(conversationSenderConfig());
    $to = PhoneNumber::fromString('+15551234567');
    $first = $sender->send($to, 'first');
    $second = $sender->send($to, 'second');

    expect($first->providerMessageId)->toBe('IM999');
    expect($second->providerMessageId)->toBe('IM999');
    expect($second->metadata['conversation_reused'])->toBeTrue();

    // One conversation created, but two messages posted into it.
    expect(Http::recorded(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/v1/Conversations'))->count())->toBe(1);
    expect(Http::recorded(fn ($request) => str_ends_with($request->url(), '/Messages'))->count())->toBe(2);
});

it('deletes a newly created conversation when the message send fails', function () {
    Http::fake([
        'conversations.twilio.com/v1/Conversations' => Http::response(['sid' => 'CH123'], 201),
        'conversations.twilio.com/v1/Conversations/CH123/Participants' => Http::response(['sid' => 'MB123'], 201),
        'conversations.twilio.com/v1/Conversations/CH123/Messages' => Http::response(['message' => 'boom'], 400),
        'conversations.twilio.com/v1/Conversations/CH123' => Http::response('', 204),
    ]);

    $sender = new TwilioSender(conversationSenderConfig());

    expect(fn () => $sender->send(PhoneNumber::fromString('+15551234567'), 'Hello'))
        ->toThrow(TextoSendFailedException::class);

    // The failed send must not leave an orphaned conversation behind.
    Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/Conversations/CH123'));
});

it('falls back to a fresh conversation when the cached one is gone', function () {
    Http::fake([
        'conversations.twilio.com/v1/Conversations/CHSTALE/Messages' => Http::response(['message' => 'not found'], 404),
        'conversations.twilio.com/v1/Conversations' => Http::response(['sid' => 'CH123'], 201),
        'conversations.twilio.com/v1/Conversations/CH123/Participants' => Http::response(['sid' => 'MB123'], 201),
        'conversations.twilio.com/v1/Conversations/CH123/Messages' => Http::response(['sid' => 'IM999', 'status' => 'sent'], 201),
    ]);
    $cacheKey = 'texto:twilio:conversation:ACXXXX:+15550001111:+15551234567';
    Cache::put($cacheKey, 'CHSTALE', 3600);

    $sender = new TwilioSender(conversationSenderConfig());
    $result = $sender->send(PhoneNumber::fromString('+15551234567'), 'Hello');

    expect($result->providerMessageId)->toBe('IM999');
    expect(Cache::get($cacheKey))->toBe('CH123');
});
