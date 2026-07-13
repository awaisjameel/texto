<?php

use Awaisjameel\Texto\Exceptions\TextoWebhookValidationException;
use Awaisjameel\Texto\ValueObjects\PhoneNumber;
use Awaisjameel\Texto\Webhooks\TwilioWebhookHandler;
use Illuminate\Http\Request;

function sign(array $params, string $token, string $url): string
{
    ksort($params);
    $data = $url;
    foreach ($params as $k => $v) {
        $data .= $k.$v;
    }

    return base64_encode(hash_hmac('sha1', $data, $token, true));
}

it('validates signature and returns status result', function () {
    $url = 'https://example.com/texto/webhook/twilio';
    $params = ['MessageStatus' => 'delivered', 'MessageSid' => 'SM123'];
    $sig = sign($params, 'auth-token', $url);
    $request = Request::create($url, 'POST', $params, [], [], ['HTTP_X_TWILIO_SIGNATURE' => $sig]);
    config()->set('texto.twilio.auth_token', 'auth-token');
    $handler = new TwilioWebhookHandler;
    $result = $handler->handle($request);
    expect($result->status->value)->toBe('delivered');
});

it('rejects invalid signature', function () {
    $url = 'https://example.com/texto/webhook/twilio';
    $params = ['MessageStatus' => 'delivered', 'MessageSid' => 'SM123'];
    $request = Request::create($url, 'POST', $params, [], [], ['HTTP_X_TWILIO_SIGNATURE' => 'bad']);
    config()->set('texto.twilio.auth_token', 'auth-token');
    $handler = new TwilioWebhookHandler;
    $handler->handle($request);
})->throws(TextoWebhookValidationException::class);

it('validates signatures for webhook URLs that carry a query string', function () {
    // Twilio signs the full URL (query included) plus the POST body fields only; folding
    // query params into the concatenated list (the old $request->all()) always failed here.
    $url = 'https://example.com/texto/webhook/twilio?foo=bar';
    $params = ['MessageStatus' => 'delivered', 'MessageSid' => 'SM456'];
    $sig = sign($params, 'auth-token', $url);
    $request = Request::create($url, 'POST', $params, [], [], ['HTTP_X_TWILIO_SIGNATURE' => $sig]);
    config()->set('texto.twilio.auth_token', 'auth-token');
    $handler = new TwilioWebhookHandler;
    $result = $handler->handle($request);
    expect($result->status->value)->toBe('delivered');
});

it('ignores conversation events authored by the configured from number', function () {
    // Conversations fires onMessageAdded for our own outbound sends; the echo must not
    // become a stored inbound message or a MessageReceived event.
    config()->set('texto.twilio.auth_token', 'auth-token');
    config()->set('texto.twilio.from_number', '+15550001111');
    $url = 'https://example.com/texto/webhook/twilio';
    $params = ['EventType' => 'onMessageAdded', 'Author' => '+15550001111', 'Body' => 'our own message', 'MessageSid' => 'IM1', 'ConversationSid' => 'CH1'];
    $sig = sign($params, 'auth-token', $url);
    $request = Request::create($url, 'POST', $params, [], [], ['HTTP_X_TWILIO_SIGNATURE' => $sig]);
    $handler = new TwilioWebhookHandler;
    expect($handler->handle($request))->toBeNull();
});

it('still maps conversation events from other authors as inbound', function () {
    config()->set('texto.twilio.auth_token', 'auth-token');
    config()->set('texto.twilio.from_number', '+15550001111');
    $url = 'https://example.com/texto/webhook/twilio';
    $params = ['EventType' => 'onMessageAdded', 'Author' => '+15551234567', 'Body' => 'customer reply', 'MessageSid' => 'IM2', 'ConversationSid' => 'CH1'];
    $sig = sign($params, 'auth-token', $url);
    $request = Request::create($url, 'POST', $params, [], [], ['HTTP_X_TWILIO_SIGNATURE' => $sig]);
    $handler = new TwilioWebhookHandler;
    $result = $handler->handle($request);
    expect($result)->not->toBeNull();
    expect($result->from->e164)->toBe('+15551234567');
    expect($result->body)->toBe('customer reply');
});

it('parses inbound message', function () {
    $url = 'https://example.com/texto/webhook/twilio';
    $params = ['From' => '+15551234567', 'To' => '+15550001111', 'Body' => 'Hi', 'MediaUrl0' => 'https://x/img.jpg', 'NumMedia' => 1];
    $sig = sign($params, 'auth-token', $url);
    $request = Request::create($url, 'POST', $params, [], [], ['HTTP_X_TWILIO_SIGNATURE' => $sig]);
    config()->set('texto.twilio.auth_token', 'auth-token');
    $handler = new TwilioWebhookHandler;
    $result = $handler->handle($request);
    expect($result->body)->toBe('Hi');
    expect($result->mediaUrls)->toHaveCount(1);
    expect($result->from)->toEqual(PhoneNumber::fromString('+15551234567'));
});
