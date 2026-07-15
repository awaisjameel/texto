<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Contracts;

/**
 * A message endpoint address. Implementations are immutable value objects:
 * PhoneNumber (E.164 / alphanumeric sender ID) for SMS/MMS/WhatsApp and
 * EmailAddress (RFC 5321) for the email driver.
 */
interface AddressInterface extends \Stringable
{
    /**
     * Canonical string form of the address (E.164 for phone numbers,
     * the bare mailbox address for email).
     */
    public function value(): string;
}
