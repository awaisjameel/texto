<?php

declare(strict_types=1);

use Awaisjameel\Texto\Exceptions\TextoException;
use Awaisjameel\Texto\ValueObjects\EmailAddress;

it('parses a plain email address', function () {
    $address = EmailAddress::fromString('user@example.com');

    expect($address->address)->toBe('user@example.com');
    expect($address->displayName)->toBeNull();
    expect($address->value())->toBe('user@example.com');
    expect((string) $address)->toBe('user@example.com');
});

it('parses the name-addr form and keeps the display name', function () {
    $address = EmailAddress::fromString('Jane Doe <jane@example.com>');

    expect($address->address)->toBe('jane@example.com');
    expect($address->displayName)->toBe('Jane Doe');
});

it('parses a quoted display name', function () {
    $address = EmailAddress::fromString('"Doe, Jane" <jane@example.com>');

    expect($address->address)->toBe('jane@example.com');
    expect($address->displayName)->toBe('Doe, Jane');
});

it('lowercases the domain but preserves the local part', function () {
    $address = EmailAddress::fromString('John.Smith@EXAMPLE.COM');

    expect($address->address)->toBe('John.Smith@example.com');
});

it('trims surrounding whitespace', function () {
    expect(EmailAddress::fromString('  user@example.com  ')->address)->toBe('user@example.com');
});

it('rejects an empty string', function () {
    EmailAddress::fromString('   ');
})->throws(TextoException::class);

it('rejects invalid addresses', function (string $raw) {
    EmailAddress::fromString($raw);
})->with([
    'not-an-email',
    'missing-domain@',
    '@missing-local.com',
    'spaces in@example.com',
    'Jane <not-an-email>',
])->throws(TextoException::class);
