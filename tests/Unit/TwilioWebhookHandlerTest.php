<?php

use Awaisjameel\Texto\Webhooks\TwilioWebhookHandler;
use Illuminate\Http\Request;
use Awaisjameel\Texto\ValueObjects\PhoneNumber;

function sign(array $params, string $token, string $url): string
{
    ksort($params);
    $data = $url;
    foreach ($params as $k => $v) {
        $data .= $k . $v;
    }
    return base64_encode(hash_hmac('sha1', $data, $token, true));
}

it('validates signature and returns status result', function () {
    $url = 'https://example.com/texto/webhook/twilio';
    $params = ['MessageStatus' => 'delivered', 'MessageSid' => 'SM123'];
    $sig = sign($params, 'auth-token', $url);
    $request = Request::create($url, 'POST', $params, [], [], ['HTTP_X_TWILIO_SIGNATURE' => $sig]);
    config()->set('texto.twilio.auth_token', 'auth-token');
    $handler = new TwilioWebhookHandler();
    $result = $handler->handle($request);
    expect($result->status->value)->toBe('delivered');
});

it('rejects invalid signature', function () {
    $url = 'https://example.com/texto/webhook/twilio';
    $params = ['MessageStatus' => 'delivered', 'MessageSid' => 'SM123'];
    $request = Request::create($url, 'POST', $params, [], [], ['HTTP_X_TWILIO_SIGNATURE' => 'bad']);
    config()->set('texto.twilio.auth_token', 'auth-token');
    $handler = new TwilioWebhookHandler();
    $handler->handle($request);
})->throws(\Awaisjameel\Texto\Exceptions\TextoWebhookValidationException::class);

it('parses inbound message', function () {
    $url = 'https://example.com/texto/webhook/twilio';
    $params = ['From' => '+15551234567', 'To' => '+15550001111', 'Body' => 'Hi', 'MediaUrl0' => 'https://x/img.jpg', 'NumMedia' => 1];
    $sig = sign($params, 'auth-token', $url);
    $request = Request::create($url, 'POST', $params, [], [], ['HTTP_X_TWILIO_SIGNATURE' => $sig]);
    config()->set('texto.twilio.auth_token', 'auth-token');
    $handler = new TwilioWebhookHandler();
    $result = $handler->handle($request);
    expect($result->body)->toBe('Hi');
    expect($result->mediaUrls)->toHaveCount(1);
    expect($result->from)->toEqual(PhoneNumber::fromString('+15551234567'));
});
