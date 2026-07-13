<?php

declare(strict_types=1);

use Awaisjameel\Texto\Exceptions\WhatsappApiAuthException;
use Awaisjameel\Texto\Exceptions\WhatsappApiException;
use Awaisjameel\Texto\Exceptions\WhatsappApiRateLimitException;
use Awaisjameel\Texto\Exceptions\WhatsappApiValidationException;
use Awaisjameel\Texto\Support\WhatsappApi;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('texto.whatsapp.access_token', 'token');
    config()->set('texto.whatsapp.phone_number_id', 'phone-id');
});

it('sends a Graph API message and returns the decoded response', function () {
    Http::fake(['https://graph.facebook.com/v25.0/phone-id/messages' => Http::response([
        'contacts' => [['wa_id' => '923001234567']],
        'messages' => [['id' => 'wamid.123', 'message_status' => 'accepted']],
    ])]);

    $response = (new WhatsappApi('token', 'phone-id'))->sendMessage([
        'messaging_product' => 'whatsapp', 'to' => '+923001234567', 'type' => 'text', 'text' => ['body' => 'Hello'],
    ]);

    expect($response['messages'][0]['id'])->toBe('wamid.123');
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer token')
        && $request->data()['to'] === '+923001234567');
});

it('maps invalid Graph access tokens to an auth exception', function () {
    Http::fake(['https://graph.facebook.com/*' => Http::response(['error' => ['code' => 190, 'message' => 'No token']], 401)]);

    (new WhatsappApi('token', 'phone-id'))->sendMessage([]);
})->throws(WhatsappApiAuthException::class);

it('maps Graph rate limits to a rate-limit exception', function () {
    Http::fake(['https://graph.facebook.com/*' => Http::response(['error' => ['code' => 130429, 'message' => 'Slow down']], 429)]);

    (new WhatsappApi('token', 'phone-id'))->sendMessage([]);
})->throws(WhatsappApiRateLimitException::class);

it('maps invalid Graph parameters to a validation exception', function () {
    Http::fake(['https://graph.facebook.com/*' => Http::response(['error' => ['code' => 100, 'message' => 'Bad parameter']], 400)]);

    (new WhatsappApi('token', 'phone-id'))->sendMessage([]);
})->throws(WhatsappApiValidationException::class);

it('preserves non-validation Graph codes and error details', function () {
    Http::fake(['https://graph.facebook.com/*' => Http::response(['error' => [
        'code' => 131047,
        'message' => 'Generic message',
        'error_data' => ['details' => 'The 24-hour window has elapsed.'],
    ]], 400)]);

    try {
        (new WhatsappApi('token', 'phone-id'))->sendMessage([]);
    } catch (WhatsappApiException $exception) {
        expect($exception->graphCode)->toBe('131047');
        expect($exception->getMessage())->toBe('The 24-hour window has elapsed.');

        return;
    }

    throw new RuntimeException('Expected WhatsApp API exception.');
});
