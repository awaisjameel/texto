<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\ValueObjects;

use Awaisjameel\Texto\Contracts\AddressInterface;
use Awaisjameel\Texto\Exceptions\TextoException;

final class EmailAddress implements AddressInterface
{
    public function __construct(
        /** Bare mailbox address, e.g. "user@example.com". */
        public readonly string $address,
        /** Optional display name parsed from "Name <user@example.com>" input. */
        public readonly ?string $displayName = null,
    ) {}

    public static function fromString(string $raw): self
    {
        $raw = trim($raw);
        if ($raw === '') {
            throw new TextoException('Empty email address.');
        }

        // Accept the RFC 5322 name-addr form ("Jane Doe <jane@example.com>") because inbound
        // webhooks and user input commonly carry it; the display name is kept separately so the
        // canonical value stays a bare mailbox address.
        $displayName = null;
        if (preg_match('/^(.*)<([^<>]+)>$/s', $raw, $matches) === 1) {
            $displayName = trim($matches[1], " \t\r\n\"'");
            $displayName = $displayName === '' ? null : $displayName;
            $raw = trim($matches[2]);
        }

        // The domain part of an address is case-insensitive (RFC 5321); lowercase it so equal
        // addresses compare/store identically. The local part is left untouched because it is
        // technically case-sensitive.
        $atPosition = strrpos($raw, '@');
        if ($atPosition !== false) {
            $raw = substr($raw, 0, $atPosition).'@'.strtolower(substr($raw, $atPosition + 1));
        }

        if (filter_var($raw, FILTER_VALIDATE_EMAIL) === false) {
            throw new TextoException('Invalid email address: '.$raw);
        }

        return new self($raw, $displayName);
    }

    public function value(): string
    {
        return $this->address;
    }

    public function __toString(): string
    {
        return $this->address;
    }
}
