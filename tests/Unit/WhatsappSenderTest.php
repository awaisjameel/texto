<?php

declare(strict_types=1);

use Awaisjameel\Texto\Contracts\WhatsappApiInterface;
use Awaisjameel\Texto\Drivers\WhatsappSender;
use Awaisjameel\Texto\Enums\MessageStatus;
use Awaisjameel\Texto\Exceptions\TextoSendFailedException;
use Awaisjameel\Texto\Models\Message;
use Awaisjameel\Texto\Texto;
use Awaisjameel\Texto\ValueObjects\PhoneNumber;
use Illuminate\Support\Facades\Http;

final class FakeWhatsappApi implements WhatsappApiInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $payloads = [];

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sendMessage(array $payload): array
    {
        $this->payloads[] = $payload;

        return [
            'contacts' => [['wa_id' => '15551234567']],
            'messages' => [['id' => 'wamid.123', 'message_status' => 'accepted']],
        ];
    }

    /** @return array<string, mixed> */
    public function getMediaUrl(string $mediaId): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function markAsRead(string $wamid): array
    {
        return [];
    }
}

function whatsappSender(FakeWhatsappApi $api): WhatsappSender
{
    return new WhatsappSender([
        'access_token' => 'token',
        'phone_number_id' => 'phone-id',
        'from_number' => '+15550003333',
    ], $api);
}

it('builds a canonical text payload and maps the initial provider status', function () {
    $api = new FakeWhatsappApi;

    $result = whatsappSender($api)->send(PhoneNumber::fromString('+15551234567'), 'Hello');

    expect($api->payloads)->toBe([[
        'messaging_product' => 'whatsapp',
        'to' => '15551234567',
        'type' => 'text',
        'text' => ['body' => 'Hello', 'preview_url' => false],
    ]]);
    expect($result->from?->e164)->toBe('+15550003333');
    expect($result->status)->toBe(MessageStatus::Sending);
    expect($result->metadata['whatsapp_wa_id'])->toBe('15551234567');
});

it('uses an explicit local sender without changing the Graph API sender', function () {
    $api = new FakeWhatsappApi;

    $result = whatsappSender($api)->send(
        PhoneNumber::fromString('+15551234567'),
        'Hello',
        PhoneNumber::fromString('+15550004444'),
    );

    expect($result->from?->e164)->toBe('+15550004444');
    expect($api->payloads[0])->not->toHaveKey('from');
});

it('resolves the WhatsApp driver through Texto and persists its canonical result', function () {
    config()->set('texto.driver', 'whatsapp');
    config()->set('texto.queue', false);
    config()->set('texto.store_messages', true);
    config()->set('texto.whatsapp', [
        'access_token' => 'token',
        'phone_number_id' => 'phone-id',
        'from_number' => '+15550003333',
    ]);
    Http::fake(['https://graph.facebook.com/v25.0/phone-id/messages' => Http::response([
        'contacts' => [['wa_id' => '15551234567']],
        'messages' => [['id' => 'wamid.123', 'message_status' => 'accepted']],
    ])]);

    $result = app(Texto::class)->send('+15551234567', 'Hello');

    expect($result->status)->toBe(MessageStatus::Sending);
    expect(Message::where('provider_message_id', 'wamid.123')->value('from_number'))->toBe('+15550003333');
    Http::assertSent(fn ($request) => $request->data()['to'] === '15551234567');
});

it('does not reuse the global API client for per-send WhatsApp tenant overrides', function () {
    $globalApi = new FakeWhatsappApi;
    app()->instance(WhatsappApiInterface::class, $globalApi);
    config()->set('texto.driver', 'whatsapp');
    config()->set('texto.queue', false);
    config()->set('texto.store_messages', false);
    config()->set('texto.whatsapp', [
        'access_token' => 'global-token',
        'phone_number_id' => 'global-phone-id',
    ]);
    Http::fake(['https://graph.facebook.com/v25.0/tenant-phone-id/messages' => Http::response([
        'contacts' => [['wa_id' => '15551234567']],
        'messages' => [['id' => 'wamid.tenant', 'message_status' => 'accepted']],
    ])]);

    $result = app(Texto::class)->send('+15551234567', 'Hello', [
        'driver' => 'whatsapp',
        'driver_config' => [
            'access_token' => 'tenant-token',
            'phone_number_id' => 'tenant-phone-id',
        ],
    ]);

    expect($result->providerMessageId)->toBe('wamid.tenant');
    expect($globalApi->payloads)->toBe([]);
    Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/v25.0/tenant-phone-id/messages'
        && $request->hasHeader('Authorization', 'Bearer tenant-token'));
});

it('rejects sends that would require more than one provider message', function () {
    $api = new FakeWhatsappApi;
    $sender = whatsappSender($api);

    $sender->send(PhoneNumber::fromString('+15551234567'), 'Caption', null, [
        'https://example.com/one.jpg',
        'https://example.com/two.jpg',
    ]);
})->throws(TextoSendFailedException::class, 'one media URL per send');

it('rejects empty and audio-caption sends before calling the provider', function () {
    $api = new FakeWhatsappApi;
    $sender = whatsappSender($api);

    expect(fn () => $sender->send(PhoneNumber::fromString('+15551234567'), ''))->toThrow(
        TextoSendFailedException::class,
        'message body, media URL, or template',
    );
    expect(fn () => $sender->send(PhoneNumber::fromString('+15551234567'), 'Listen', null, ['https://example.com/voice.ogg']))->toThrow(
        TextoSendFailedException::class,
        'cannot include a caption',
    );
    expect($api->payloads)->toBe([]);
});
