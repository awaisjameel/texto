<?php

declare(strict_types=1);

use Awaisjameel\Texto\Drivers\TwilioSender;
use Awaisjameel\Texto\Exceptions\TextoSendFailedException;
use Awaisjameel\Texto\Jobs\SendMessageJob;
use Awaisjameel\Texto\Models\Message;
use Awaisjameel\Texto\ValueObjects\PhoneNumber;
use Illuminate\Support\Facades\Http;

function mediaSafetySender(): TwilioSender
{
    return new TwilioSender(['account_sid' => 'ACMEDIA', 'auth_token' => 'token', 'from_number' => '+15550001111',
        'use_conversations' => true, 'mms_template_sid' => 'HXMMS', 'mms_media_base_url' => 'https://media.example/assets']);
}

it('preserves the complete media path and query without changing the host or encoding slashes', function (): void {
    Http::fake(fn ($request) => Http::response(['sid' => str_ends_with($request->url(), '/Messages') ? 'IMMEDIA' : 'CHMEDIA'], 201));
    $url = 'https://media.example/assets/images/sub/pic%20one.jpg?signature=a%2Fb';
    mediaSafetySender()->send(PhoneNumber::fromString('+15551234567'), 'caption', null, [$url]);
    Http::assertSent(function ($request) use ($url) {
        if (! str_ends_with($request->url(), '/Messages')) {
            return false;
        }
        parse_str($request->body(), $fields);
        $variables = json_decode($fields['ContentVariables'], true, flags: JSON_THROW_ON_ERROR);

        return $fields['ContentSid'] === 'HXMMS' && 'https://media.example/assets/'.$variables['media_path'] === $url;
    });
});

it('rejects an unsupported media origin before issuing any provider request', function (): void {
    Http::fake();
    expect(fn () => mediaSafetySender()->send(PhoneNumber::fromString('+15551234567'), 'caption', null, ['https://another.example/photo.jpg']))->toThrow(TextoSendFailedException::class);
    Http::assertNothingSent();
});

it('preserves a newly created conversation after an ambiguous server failure', function (): void {
    Http::fake(function ($request) {
        if (str_ends_with($request->url(), '/Messages')) {
            return Http::response(['message' => 'uncertain provider outcome'], 500);
        }
        if ($request->method() === 'DELETE') {
            return Http::response('', 204);
        }

        return Http::response(['sid' => 'CHMEDIA'], 201);
    });
    $sender = new TwilioSender(['account_sid' => 'ACMEDIA', 'auth_token' => 'token', 'from_number' => '+15550001111', 'use_conversations' => true, 'sms_template_sid' => 'HXTEXT']);
    expect(fn () => $sender->send(PhoneNumber::fromString('+15551234567'), 'body'))->toThrow(TextoSendFailedException::class);
    Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
});

it('marks an uncorrelated failed queue job ambiguous without overwriting an accepted send', function (): void {
    $record = Message::create(['driver' => 'telnyx', 'direction' => 'sent', 'status' => 'queued', 'metadata' => []]);
    $job = new SendMessageJob($record->id, '+15551234567', 'body');
    $job->failed(new RuntimeException('worker failure'));
    expect($record->fresh()->status)->toBe('ambiguous')->and($record->fresh()->error_code)->toBe('queue_job_failed');
    $record->update(['status' => 'sent', 'provider_message_id' => 'accepted']);
    $job->failed(new RuntimeException('listener failed after acceptance'));
    expect($record->fresh()->status)->toBe('sent')->and($job->tries)->toBe(1);
});
