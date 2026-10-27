<?php

declare(strict_types=1);

use Awaisjameel\Texto\Contracts\AddressInterface;
use Awaisjameel\Texto\Contracts\DriverManagerInterface;
use Awaisjameel\Texto\Contracts\MessageRepositoryInterface;
use Awaisjameel\Texto\Contracts\MessageSenderInterface;
use Awaisjameel\Texto\Drivers\FakeSender;
use Awaisjameel\Texto\Enums\Direction;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Enums\MessageStatus;
use Awaisjameel\Texto\Exceptions\TextoSendFailedException;
use Awaisjameel\Texto\Models\Message;
use Awaisjameel\Texto\Texto;
use Awaisjameel\Texto\ValueObjects\PhoneNumber;
use Awaisjameel\Texto\ValueObjects\SentMessageResult;

it('verifies Telnyx documented timestamp header and pipe-delimited payload', function (): void {
    config()->set('texto.testing.skip_webhook_validation', false);
    $keys = sodium_crypto_sign_keypair();
    config()->set('texto.telnyx.webhook_secret', base64_encode(sodium_crypto_sign_publickey($keys)));
    $body = json_encode(['data' => ['event_type' => 'message.received', 'payload' => [
        'id' => 'official-format-message', 'direction' => 'inbound',
        'from' => ['phone_number' => '+15551234567'], 'to' => [['phone_number' => '+15550001111']], 'text' => 'signed bytes',
    ]]], JSON_THROW_ON_ERROR);
    $timestamp = (string) time();
    $signature = base64_encode(sodium_crypto_sign_detached($timestamp.'|'.$body, sodium_crypto_sign_secretkey($keys)));
    $this->call('POST', '/texto/webhook/telnyx', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_TELNYX_TIMESTAMP' => $timestamp, 'HTTP_TELNYX_SIGNATURE_ED25519' => $signature,
    ], $body)->assertOk();
    expect(Message::where('provider_message_id', 'official-format-message')->value('body'))->toBe('signed bytes');
});

it('upgrades the original queued row when the send fails', function (): void {
    config()->set('texto.store_messages', true);
    config()->set('texto.queue', false);
    $queued = app(MessageRepositoryInterface::class)->storeSent(new SentMessageResult(
        Driver::Twilio, Direction::Sent, PhoneNumber::fromString('+15551234567'), null, 'queued body', [], [], MessageStatus::Queued,
    ));
    app(DriverManagerInterface::class)->extend('twilio', fn () => new class implements MessageSenderInterface
    {
        public function send(AddressInterface $to, string $body, ?AddressInterface $from = null, array $mediaUrls = [], array $metadata = []): SentMessageResult
        {
            throw new TextoSendFailedException('known rejection');
        }
    });
    $result = app(Texto::class)->send('+15551234567', 'queued body', ['driver' => 'twilio', 'queued_job' => true, 'queued_message_id' => $queued->id]);
    expect($result->status)->toBe(MessageStatus::Failed)->and(Message::count())->toBe(1)
        ->and($queued->fresh()->status)->toBe('failed');
});

it('resolves the default sender after applying a per-send override', function (): void {
    config()->set('texto.store_messages', false);
    config()->set('texto.twilio.from_number', '+15550001111');
    app(DriverManagerInterface::class)->extend('twilio', fn () => new FakeSender);
    $result = app(Texto::class)->sendDirect('+15551234567', 'override', [
        'driver' => 'twilio', 'driver_config' => ['from_number' => '+15550002222'],
    ]);
    expect($result->from->value())->toBe('+15550002222')
        ->and(config('texto.twilio.from_number'))->toBe('+15550001111');
});

it('does not regress a newer webhook status when persisting a stale polling result', function (): void {
    $record = Message::create(['driver' => 'telnyx', 'direction' => 'sent', 'status' => 'sent', 'metadata' => []]);
    $stale = Message::findOrFail($record->id);
    $record->update(['status' => 'read']);
    app(MessageRepositoryInterface::class)->updatePolledStatus($stale, MessageStatus::Delivered);
    expect($record->fresh()->status)->toBe('read');
});
