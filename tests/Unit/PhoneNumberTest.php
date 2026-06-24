<?php

declare(strict_types=1);

use Awaisjameel\Texto\Exceptions\TextoException;
use Awaisjameel\Texto\ValueObjects\PhoneNumber;

it('normalizes a number to E.164', function () {
    expect(PhoneNumber::fromString('+12025550123')->e164)->toBe('+12025550123');
    expect(PhoneNumber::fromString('(202) 555-0123', 'US')->e164)->toBe('+12025550123');
});

it('accepts structurally possible numbers that strict validation would reject', function () {
    // Fictional/region-unassigned ranges are not isValidNumber but are routinely used as senders.
    expect(PhoneNumber::fromString('+15551234567')->e164)->toBe('+15551234567');
});

it('accepts alphanumeric sender IDs verbatim', function () {
    expect(PhoneNumber::fromString('Acme')->e164)->toBe('Acme');
    expect(PhoneNumber::fromString('Acme Corp')->e164)->toBe('Acme Corp');
    expect(PhoneNumber::fromString('Bank2You')->e164)->toBe('Bank2You');
});

it('rejects empty input', function () {
    PhoneNumber::fromString('   ');
})->throws(TextoException::class);

it('rejects genuinely malformed input', function () {
    PhoneNumber::fromString('notvalid!!', 'US');
})->throws(TextoException::class);
