<?php

declare(strict_types=1);

use Awaisjameel\Texto\Drivers\EmailSender;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Enums\MessageStatus;
use Awaisjameel\Texto\Exceptions\TextoSendFailedException;
use Awaisjameel\Texto\Mail\TextoMail;
use Awaisjameel\Texto\ValueObjects\EmailAddress;
use Awaisjameel\Texto\ValueObjects\PhoneNumber;
use Illuminate\Support\Facades\Mail;

it('sends an email with subject, html, cc, bcc and reply-to through the laravel mailer', function () {
    Mail::fake();
    $sender = new EmailSender([
        'from_address' => 'noreply@myapp.com',
        'from_name' => 'My App',
        'default_subject' => 'Default subject',
    ]);

    $result = $sender->send(new EmailAddress('user@example.com', 'User'), 'Hello body', null, [], [
        'email' => [
            'subject' => 'Greetings',
            'html' => '<p>Hello</p>',
            'cc' => ['cc@example.com'],
            'bcc' => ['bcc@example.com'],
            'reply_to' => 'reply@example.com',
        ],
    ]);

    expect($result->driver)->toBe(Driver::Email);
    expect($result->status)->toBe(MessageStatus::Sent);
    expect($result->to->value())->toBe('user@example.com');
    expect($result->from?->value())->toBe('noreply@myapp.com');
    expect($result->metadata['email']['subject'])->toBe('Greetings');

    Mail::assertSent(TextoMail::class, function (TextoMail $mail) {
        return $mail->hasTo('user@example.com')
            && $mail->emailSubject === 'Greetings'
            && $mail->textBody === 'Hello body'
            && $mail->htmlBody === '<p>Hello</p>'
            && $mail->fromAddress?->address === 'noreply@myapp.com'
            && $mail->fromAddress->name === 'My App'
            && ($mail->ccAddresses[0]->address ?? null) === 'cc@example.com'
            && ($mail->bccAddresses[0]->address ?? null) === 'bcc@example.com'
            && ($mail->replyToAddresses[0]->address ?? null) === 'reply@example.com';
    });
});

it('applies the default subject when none is provided', function () {
    Mail::fake();
    $sender = new EmailSender(['from_address' => 'noreply@myapp.com', 'default_subject' => 'Default subject']);

    $result = $sender->send(new EmailAddress('user@example.com'), 'Body');

    expect($result->metadata['email']['subject'])->toBe('Default subject');
    Mail::assertSent(TextoMail::class, fn (TextoMail $mail) => $mail->emailSubject === 'Default subject');
});

it('falls back to the application global from address', function () {
    Mail::fake();
    config()->set('mail.from.address', 'app@myapp.com');
    config()->set('mail.from.name', 'App Name');

    $sender = new EmailSender([]);
    $result = $sender->send(new EmailAddress('user@example.com'), 'Body');

    expect($result->from?->value())->toBe('app@myapp.com');
});

it('turns media urls and attachment options into attachment specs', function () {
    Mail::fake();
    $sender = new EmailSender(['from_address' => 'noreply@myapp.com']);

    $sender->send(new EmailAddress('user@example.com'), 'Body', null, ['https://cdn.example.com/pic.jpg'], [
        'email' => [
            'attachments' => [
                '/tmp/report.pdf',
                ['url' => 'https://cdn.example.com/doc.pdf', 'name' => 'doc.pdf', 'mime' => 'application/pdf'],
            ],
        ],
    ]);

    Mail::assertSent(TextoMail::class, function (TextoMail $mail) {
        return $mail->attachmentSpecs === [
            ['path' => '/tmp/report.pdf'],
            ['url' => 'https://cdn.example.com/doc.pdf', 'name' => 'doc.pdf', 'mime' => 'application/pdf'],
            ['url' => 'https://cdn.example.com/pic.jpg'],
        ];
    });
});

it('sends through the array transport and captures the rfc message id', function () {
    config()->set('mail.default', 'array');
    $sender = new EmailSender(['from_address' => 'noreply@myapp.com']);

    $result = $sender->send(new EmailAddress('user@example.com'), 'Plain text body', null, [], [
        'email' => ['subject' => 'Real render', 'html' => '<p>Rendered</p>'],
    ]);

    expect($result->providerMessageId)->not->toBeNull();
});

it('rejects a phone number recipient', function () {
    $sender = new EmailSender([]);
    $sender->send(PhoneNumber::fromString('+15551234567'), 'Body');
})->throws(TextoSendFailedException::class);

it('rejects a phone number from address', function () {
    $sender = new EmailSender([]);
    $sender->send(new EmailAddress('user@example.com'), 'Body', PhoneNumber::fromString('+15551234567'));
})->throws(TextoSendFailedException::class);

it('rejects invalid cc addresses', function () {
    Mail::fake();
    $sender = new EmailSender(['from_address' => 'noreply@myapp.com']);
    $sender->send(new EmailAddress('user@example.com'), 'Body', null, [], [
        'email' => ['cc' => ['not-an-email']],
    ]);
})->throws(TextoSendFailedException::class);

it('rejects an invalid configured from address', function () {
    Mail::fake();
    $sender = new EmailSender(['from_address' => 'broken']);
    $sender->send(new EmailAddress('user@example.com'), 'Body');
})->throws(TextoSendFailedException::class);
