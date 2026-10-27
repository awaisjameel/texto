<?php

declare(strict_types=1);

use Awaisjameel\Texto\Exceptions\TelnyxApiException;
use Awaisjameel\Texto\Exceptions\TwilioApiException;
use Awaisjameel\Texto\Exceptions\WhatsappApiException;
use Awaisjameel\Texto\Support\TelnyxMessagingApi;
use Awaisjameel\Texto\Support\TwilioContentApi;
use Awaisjameel\Texto\Support\TwilioConversationsApi;
use Awaisjameel\Texto\Support\TwilioMessagingApi;
use Awaisjameel\Texto\Support\WhatsappApi;
use Illuminate\Support\Facades\Http;

it('rejects malformed successful provider message responses', function (): void {
    Http::fake(['*' => Http::response('{}', 201)]);
    expect(fn () => (new TwilioMessagingApi('ACXXX', 'token'))->sendMessage('+15551234567', '+15550001111', 'body'))->toThrow(TwilioApiException::class)
        ->and(fn () => (new TelnyxMessagingApi('key'))->sendMessage('+15551234567', '+15550001111', 'body'))->toThrow(TelnyxApiException::class)
        ->and(fn () => (new WhatsappApi('key', 'phone'))->sendMessage([]))->toThrow(WhatsappApiException::class);
});

it('rejects non-object successful JSON and does not follow redirects', function (): void {
    Http::fake(['*' => Http::response('[]', 200)]);
    expect(fn () => (new TwilioMessagingApi('ACXXX', 'token'))->fetchMessage('SM1'))->toThrow(TwilioApiException::class);
    Http::fake(['*' => Http::response('', 302, ['Location' => 'https://other.example/steal'])]);
    expect(fn () => (new TelnyxMessagingApi('key'))->fetchMessage('message'))->toThrow(TelnyxApiException::class);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'other.example'));
});

it('searches every content page before creating a duplicate template', function (): void {
    Http::fake(['content.twilio.com/*' => Http::sequence()
        ->push(['contents' => [['sid' => 'HXOTHER', 'friendly_name' => 'other']], 'meta' => ['next_page_url' => 'https://content.twilio.com/v1/Content?PageToken=next']])
        ->push(['contents' => [['sid' => 'HXFOUND', 'friendly_name' => 'desired']], 'meta' => ['next_page_url' => null]])]);
    expect((new TwilioContentApi('ACXXX', 'token'))->ensureTemplate('desired', fn () => throw new RuntimeException('Must not create a template.')))->toBe('HXFOUND');
    Http::assertSentCount(2);
});

it('rejects pagination to another host before sending credentials there', function (): void {
    Http::fake(['content.twilio.com/*' => Http::response(['contents' => [], 'meta' => ['next_page_url' => 'https://other.example/content']])]);
    expect(fn () => (new TwilioContentApi('ACXXX', 'token'))->findTemplateByFriendlyName('missing'))->toThrow(TwilioApiException::class);
    Http::assertSentCount(1);
});

it('does not retry uncertain template creation with a different payload', function (): void {
    Http::fake(['content.twilio.com/*' => Http::response(['message' => 'uncertain server outcome'], 500)]);
    expect(fn () => (new TwilioContentApi('ACXXX', 'token'))->createTemplate(['friendly_name' => 'desired']))->toThrow(TwilioApiException::class);
    Http::assertSentCount(1);
});

it('updates only the matching conversation webhook and preserves other integrations', function (): void {
    Http::fake([
        'conversations.twilio.com/v1/Conversations/CH1/Webhooks' => Http::response(['webhooks' => [
            ['sid' => 'WHOTHER', 'target' => 'webhook', 'configuration' => ['url' => 'https://other.example/integration']],
            ['sid' => 'WHOWN', 'target' => 'webhook', 'configuration' => ['url' => 'https://app.example/texto/webhook/twilio']],
        ]]),
        'conversations.twilio.com/v1/Conversations/CH1/Webhooks/WHOWN' => Http::response(['sid' => 'WHOWN']),
    ]);
    $webhook = (new TwilioConversationsApi('ACXXX', 'token'))->attachWebhook('CH1', 'https://app.example/texto/webhook/twilio');
    expect($webhook['sid'])->toBe('WHOWN');
    Http::assertSentCount(2);
    Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
    Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/WHOWN')
        && ! str_contains($request->body(), 'ReplayAfter') && ! str_contains($request->body(), 'Configuration.Triggers'));
});
