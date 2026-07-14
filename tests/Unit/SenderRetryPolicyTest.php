<?php

declare(strict_types=1);

use Awaisjameel\Texto\Drivers\TelnyxSender;
use Awaisjameel\Texto\Drivers\TwilioSender;
use Awaisjameel\Texto\Exceptions\TextoSendFailedException;
use Awaisjameel\Texto\ValueObjects\PhoneNumber;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('texto.retry.max_attempts', 3);
    config()->set('texto.retry.backoff_start_ms', 1);
});

function retryTelnyxSender(): TelnyxSender
{
    return new TelnyxSender([
        'api_key' => 'key-123',
        'from_number' => '+15550001111',
        'messaging_profile_id' => 'profile-abc',
    ]);
}

function retryTwilioSender(): TwilioSender
{
    return new TwilioSender([
        'account_sid' => 'ACXXXX',
        'auth_token' => 'token',
        'from_number' => '+15550001111',
        'use_conversations' => false,
    ]);
}

it('does not retry telnyx validation errors', function () {
    Http::fake([
        'api.telnyx.com/*' => Http::response(['errors' => [['code' => '40310', 'detail' => 'Invalid destination']]], 422),
    ]);

    $sender = retryTelnyxSender();

    expect(fn () => $sender->send(PhoneNumber::fromString('+15551234567'), 'Hello'))
        ->toThrow(TextoSendFailedException::class);

    Http::assertSentCount(1);
});

it('does not retry ambiguous telnyx 5xx failures that may have sent a message', function () {
    Http::fake([
        'api.telnyx.com/*' => Http::response(['errors' => [['code' => '10007', 'detail' => 'Internal error']]], 500),
    ]);

    $sender = retryTelnyxSender();

    expect(fn () => $sender->send(PhoneNumber::fromString('+15551234567'), 'Hello'))
        ->toThrow(TextoSendFailedException::class);

    Http::assertSentCount(1);
});

it('retries telnyx rate-limit rejections until success', function () {
    Http::fake([
        'api.telnyx.com/*' => Http::sequence()
            ->push(['errors' => [['code' => '10011', 'detail' => 'Too many requests']]], 429)
            ->push(['data' => [
                'id' => 'tel-msg-1',
                'to' => [['phone_number' => '+15551234567', 'status' => 'queued']],
                'parts' => 1,
            ]], 200),
    ]);

    $sender = retryTelnyxSender();
    $result = $sender->send(PhoneNumber::fromString('+15551234567'), 'Hello');

    expect($result->providerMessageId)->toBe('tel-msg-1');
    Http::assertSentCount(2);
});

it('does not retry twilio validation errors', function () {
    Http::fake([
        'api.twilio.com/*' => Http::response(['code' => 21211, 'message' => 'Invalid To number'], 400),
    ]);

    $sender = retryTwilioSender();

    expect(fn () => $sender->send(PhoneNumber::fromString('+15551234567'), 'Hello'))
        ->toThrow(TextoSendFailedException::class);

    Http::assertSentCount(1);
});

it('does not retry ambiguous twilio 5xx failures that may have sent a message', function () {
    Http::fake([
        'api.twilio.com/*' => Http::response(['code' => 20500, 'message' => 'Internal error'], 500),
    ]);

    $sender = retryTwilioSender();

    expect(fn () => $sender->send(PhoneNumber::fromString('+15551234567'), 'Hello'))
        ->toThrow(TextoSendFailedException::class);

    Http::assertSentCount(1);
});

it('retries twilio rate-limit rejections until success', function () {
    Http::fake([
        'api.twilio.com/*' => Http::sequence()
            ->push(['code' => 20429, 'message' => 'Too many requests'], 429)
            ->push(['sid' => 'SM123', 'status' => 'queued'], 201),
    ]);

    $sender = retryTwilioSender();
    $result = $sender->send(PhoneNumber::fromString('+15551234567'), 'Hello');

    expect($result->providerMessageId)->toBe('SM123');
    Http::assertSentCount(2);
});
