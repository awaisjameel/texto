<?php

declare(strict_types=1);

use Awaisjameel\Texto\Enums\Direction;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Enums\MessageStatus;
use Awaisjameel\Texto\Exceptions\TextoWebhookValidationException;
use Awaisjameel\Texto\Webhooks\EmailWebhookHandler;
use Illuminate\Http\Request;

function emailWebhookRequest(array $payload, ?string $secret = null, ?string $querySecret = null): Request
{
    $uri = '/texto/webhook/email'.($querySecret !== null ? '?secret='.urlencode($querySecret) : '');
    $request = Request::create($uri, 'POST', [], [], [], [], json_encode($payload, JSON_THROW_ON_ERROR));
    $request->headers->set('Content-Type', 'application/json');
    if ($secret !== null) {
        $request->headers->set('X-Texto-Secret', $secret);
    }

    return $request;
}

afterEach(function () {
    config()->set('texto.webhook.secret', null);
});

it('refuses to operate without a configured shared secret', function () {
    config()->set('texto.webhook.secret', null);
    (new EmailWebhookHandler)->handle(emailWebhookRequest(['event' => 'status', 'message_id' => 'x', 'status' => 'delivered'], 'anything'));
})->throws(TextoWebhookValidationException::class);

it('rejects a wrong secret', function () {
    config()->set('texto.webhook.secret', 'right');
    (new EmailWebhookHandler)->handle(emailWebhookRequest(['event' => 'status', 'message_id' => 'x', 'status' => 'delivered'], 'wrong'));
})->throws(TextoWebhookValidationException::class);

it('accepts the secret as a query parameter for providers that cannot send headers', function () {
    config()->set('texto.webhook.secret', 'shh');
    $result = (new EmailWebhookHandler)->handle(emailWebhookRequest(
        ['event' => 'status', 'message_id' => 'mid-1', 'status' => 'delivered'],
        null,
        'shh',
    ));

    expect($result->status)->toBe(MessageStatus::Delivered);
});

it('maps an inbound email payload', function () {
    config()->set('texto.webhook.secret', 'shh');
    $result = (new EmailWebhookHandler)->handle(emailWebhookRequest([
        'event' => 'inbound',
        'message_id' => '<abc@mail.example.com>',
        'from' => 'Jane Doe <jane@example.com>',
        'to' => 'inbox@myapp.com',
        'subject' => 'Support request',
        'text' => 'I need help',
        'html' => '<p>I need help</p>',
        'attachments' => ['https://storage.example.com/file.pdf'],
    ], 'shh'));

    expect($result->driver)->toBe(Driver::Email);
    expect($result->direction)->toBe(Direction::Received);
    expect($result->from?->value())->toBe('jane@example.com');
    expect($result->to?->value())->toBe('inbox@myapp.com');
    expect($result->body)->toBe('I need help');
    expect($result->mediaUrls)->toBe(['https://storage.example.com/file.pdf']);
    expect($result->providerMessageId)->toBe('<abc@mail.example.com>');
    expect($result->metadata['subject'])->toBe('Support request');
    expect($result->metadata['from_display_name'])->toBe('Jane Doe');
});

it('falls back to the html part when no text part exists', function () {
    config()->set('texto.webhook.secret', 'shh');
    $result = (new EmailWebhookHandler)->handle(emailWebhookRequest([
        'event' => 'inbound',
        'message_id' => 'mid-2',
        'from' => 'jane@example.com',
        'to' => 'inbox@myapp.com',
        'html' => '<p>Only html</p>',
    ], 'shh'));

    expect($result->body)->toBe('<p>Only html</p>');
});

it('maps a status payload through the email status mapper', function (string $raw, MessageStatus $expected) {
    config()->set('texto.webhook.secret', 'shh');
    $result = (new EmailWebhookHandler)->handle(emailWebhookRequest([
        'event' => 'status',
        'message_id' => 'mid-1',
        'status' => $raw,
        'error_code' => '550',
    ], 'shh'));

    expect($result->status)->toBe($expected);
    expect($result->metadata['raw_status'])->toBe($raw);
    expect($result->metadata['error_code'])->toBe('550');
})->with([
    ['delivered', MessageStatus::Delivered],
    ['opened', MessageStatus::Read],
    ['clicked', MessageStatus::Read],
    ['bounced', MessageStatus::Failed],
    ['soft_bounce', MessageStatus::Undelivered],
    ['complained', MessageStatus::Delivered],
    ['deferred', MessageStatus::Sending],
]);

it('rejects payloads without an event discriminator', function () {
    config()->set('texto.webhook.secret', 'shh');
    (new EmailWebhookHandler)->handle(emailWebhookRequest(['message_id' => 'x'], 'shh'));
})->throws(TextoWebhookValidationException::class);

it('rejects inbound payloads without a message id', function () {
    config()->set('texto.webhook.secret', 'shh');
    (new EmailWebhookHandler)->handle(emailWebhookRequest([
        'event' => 'inbound',
        'from' => 'jane@example.com',
        'to' => 'inbox@myapp.com',
        'text' => 'hi',
    ], 'shh'));
})->throws(TextoWebhookValidationException::class);

it('rejects inbound payloads with an invalid from address', function () {
    config()->set('texto.webhook.secret', 'shh');
    (new EmailWebhookHandler)->handle(emailWebhookRequest([
        'event' => 'inbound',
        'message_id' => 'mid-3',
        'from' => 'not-an-email',
        'to' => 'inbox@myapp.com',
    ], 'shh'));
})->throws(TextoWebhookValidationException::class);

it('rejects status payloads without a status', function () {
    config()->set('texto.webhook.secret', 'shh');
    (new EmailWebhookHandler)->handle(emailWebhookRequest(['event' => 'status', 'message_id' => 'mid-1'], 'shh'));
})->throws(TextoWebhookValidationException::class);
