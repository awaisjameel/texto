<?php

declare(strict_types=1);

use Awaisjameel\Texto\Models\Message;
use Awaisjameel\Texto\Support\TwilioSignatureValidator;

it('matches Twilio published signature example', function (): void {
    expect(TwilioSignatureValidator::validate('12345', 'https://example.com/myapp.php?foo=1&bar=2', [
        'CallSid' => 'CA1234567890ABCDE', 'Caller' => '+14158675310', 'Digits' => '1234', 'From' => '+14158675310', 'To' => '+18005551212',
    ], 'L/OH5YylLD5NRKLltdqwSvS0BnU='))->toBeTrue();
});

it('preserves raw query encoding and form whitespace through the HTTP boundary', function (): void {
    config()->set('texto.twilio.auth_token', 'raw-test-token');
    $url = 'http://localhost/texto/webhook/twilio?z=last&first=one%20two';
    $fields = ['From' => '+15551234567', 'To' => '+15550001111', 'Body' => '  exact body  ', 'MessageSid' => 'SMRAW'];
    $data = $url;
    $ordered = $fields;
    ksort($ordered);
    foreach ($ordered as $key => $value) {
        $data .= $key.$value;
    }
    $signature = base64_encode(hash_hmac('sha1', $data, 'raw-test-token', true));
    $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_X_TWILIO_SIGNATURE' => $signature], http_build_query($fields))->assertOk();
    expect(Message::where('provider_message_id', 'SMRAW')->value('body'))->toBe('  exact body  ');
});

it('verifies JSON body hashes and rejects a tampered signed Twilio payload', function (): void {
    config()->set('texto.twilio.auth_token', 'raw-test-token');
    $body = '{"MessageStatus":"delivered","MessageSid":"SMJSON"}';
    $url = 'http://localhost/texto/webhook/twilio?bodySHA256='.hash('sha256', $body);
    $signature = base64_encode(hash_hmac('sha1', $url, 'raw-test-token', true));
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TWILIO_SIGNATURE' => $signature];
    $this->call('POST', $url, [], [], [], $server, $body)->assertOk();
    $this->call('POST', $url, [], [], [], $server, str_replace('delivered', 'failed', $body))->assertForbidden();
});

it('returns a validation error for a short Telnyx signature instead of a Sodium exception', function (): void {
    config()->set('texto.telnyx.webhook_secret', base64_encode(str_repeat("\0", SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)));
    $this->call('POST', '/texto/webhook/telnyx', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_TELNYX_TIMESTAMP' => (string) time(), 'HTTP_TELNYX_SIGNATURE_ED25519' => base64_encode('short'),
    ], '{}')->assertForbidden()->assertJsonPath('error', 'webhook_validation_failed');
});

it('retains stringified Conversations media metadata instead of silently dropping it', function (): void {
    config()->set('texto.testing.skip_webhook_validation', true);
    config()->set('texto.twilio.auth_token', 'test-token');
    config()->set('texto.twilio.from_number', '+15550001111');
    $this->post('/texto/webhook/twilio', [
        'EventType' => 'onMessageAdded', 'Author' => '+15551234567', 'MessageSid' => 'IMWITHMEDIA', 'ConversationSid' => 'CHMEDIA',
        'Media' => json_encode([['sid' => 'MEMEDIA', 'content_type' => 'image/png']], JSON_THROW_ON_ERROR),
    ])->assertOk();
    expect(Message::where('provider_message_id', 'IMWITHMEDIA')->firstOrFail()->metadata['media'][0]['sid'])->toBe('MEMEDIA');
});
