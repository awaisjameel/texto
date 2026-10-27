<?php

declare(strict_types=1);

use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Enums\MessageStatus;
use Awaisjameel\Texto\Jobs\SendMessageJob;
use Awaisjameel\Texto\Mail\TextoMail;
use Awaisjameel\Texto\Models\Message;
use Awaisjameel\Texto\Texto;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;

afterEach(function () {
    config()->set('texto.driver', 'twilio');
    config()->set('texto.queue', false);
    config()->set('texto.email.from_address', null);
    config()->set('texto.twilio.account_sid', null);
    config()->set('texto.twilio.auth_token', null);
    config()->set('texto.twilio.from_number', null);
});

it('sends and stores an email through the email driver', function () {
    Mail::fake();
    config()->set('texto.driver', 'email');
    config()->set('texto.email.from_address', 'noreply@myapp.com');

    /** @var Texto $texto */
    $texto = app(Texto::class);
    $result = $texto->send('Jane Doe <jane@example.com>', 'Hello Jane', [
        'subject' => 'Welcome',
        'html' => '<p>Hello Jane</p>',
    ]);

    expect($result->driver)->toBe(Driver::Email);
    expect($result->status)->toBe(MessageStatus::Sent);

    $record = Message::first();
    expect($record->driver)->toBe('email');
    expect($record->direction)->toBe('sent');
    expect($record->to_number)->toBe('jane@example.com');
    expect($record->from_number)->toBe('noreply@myapp.com');
    expect($record->body)->toBe('Hello Jane');
    expect($record->metadata['email']['subject'] ?? null)->toBe('Welcome');

    Mail::assertSent(TextoMail::class, fn (TextoMail $mail) => $mail->hasTo('jane@example.com') && $mail->emailSubject === 'Welcome');
});

it('selects the email driver per send via the driver option', function () {
    Mail::fake();
    config()->set('texto.driver', 'twilio');
    config()->set('texto.email.from_address', 'noreply@myapp.com');

    /** @var Texto $texto */
    $texto = app(Texto::class);
    $result = $texto->send('jane@example.com', 'Hello', ['driver' => 'email']);

    expect($result->driver)->toBe(Driver::Email);
    Mail::assertSent(TextoMail::class);
});

it('queues email sends with the email address and options intact', function () {
    Bus::fake();
    config()->set('texto.queue', true);
    config()->set('texto.driver', 'email');
    config()->set('texto.email.from_address', 'noreply@myapp.com');

    /** @var Texto $texto */
    $texto = app(Texto::class);
    $result = $texto->send('jane@example.com', 'Queued hello', ['subject' => 'Queued subject']);

    expect($result->status)->toBe(MessageStatus::Queued);
    expect(Message::first()->to_number)->toBe('jane@example.com');

    Bus::assertDispatched(SendMessageJob::class, function (SendMessageJob $job) {
        return $job->to === 'jane@example.com'
            && ($job->options['driver'] ?? null) === 'email'
            && ($job->options['metadata']['email']['subject'] ?? null) === 'Queued subject';
    });
});

it('fails gracefully when an sms driver receives an email address', function () {
    config()->set('texto.driver', 'twilio');
    config()->set('texto.twilio.account_sid', 'ACXXXX');
    config()->set('texto.twilio.auth_token', 'token');
    config()->set('texto.twilio.from_number', '+15551112222');

    /** @var Texto $texto */
    $texto = app(Texto::class);
    $result = $texto->send('jane@example.com', 'Hello');

    expect($result->status)->toBe(MessageStatus::Failed);
    expect(Message::first()->status)->toBe('failed');
});
