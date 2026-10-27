<?php

declare(strict_types=1);

use Awaisjameel\Texto\Drivers\TwilioSender;
use Awaisjameel\Texto\Exceptions\TextoSendFailedException;
use Awaisjameel\Texto\ValueObjects\PhoneNumber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('does not retry an ambiguous message failure by creating another conversation', function (): void {
    Cache::put('texto:twilio:conversation:ACXXXX:+15550001111:+15551234567', 'CHCACHED', 3600);
    Http::fake([
        'conversations.twilio.com/v1/Conversations/CHCACHED/Messages' => Http::response(['message' => 'temporary failure after acceptance'], 500),
        'conversations.twilio.com/v1/Conversations' => Http::response(['sid' => 'CHNEW'], 201),
        'conversations.twilio.com/v1/Conversations/CHNEW/Participants' => Http::response(['sid' => 'MBNEW'], 201),
        'conversations.twilio.com/v1/Conversations/CHNEW/Messages' => Http::response(['sid' => 'IMDUPLICATE'], 201),
    ]);
    $sender = new TwilioSender([
        'account_sid' => 'ACXXXX', 'auth_token' => 'token', 'from_number' => '+15550001111',
        'use_conversations' => true, 'sms_template_sid' => 'HX111', 'mms_template_sid' => 'HX222',
    ]);
    expect(fn () => $sender->send(PhoneNumber::fromString('+15551234567'), 'one message'))->toThrow(TextoSendFailedException::class);
    Http::assertSentCount(1);
});

it('rejects multiple Conversations attachments before contacting the provider', function (): void {
    Http::fake();
    $sender = new TwilioSender([
        'account_sid' => 'ACXXXX', 'auth_token' => 'token', 'from_number' => '+15550001111', 'use_conversations' => true,
    ]);
    expect(fn () => $sender->send(PhoneNumber::fromString('+15551234567'), 'media', null, ['https://example.com/a.jpg', 'https://example.com/b.jpg']))->toThrow(TextoSendFailedException::class);
    Http::assertNothingSent();
});
