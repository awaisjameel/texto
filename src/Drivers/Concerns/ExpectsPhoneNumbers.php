<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Drivers\Concerns;

use Awaisjameel\Texto\Contracts\AddressInterface;
use Awaisjameel\Texto\Exceptions\TextoSendFailedException;
use Awaisjameel\Texto\ValueObjects\PhoneNumber;

/**
 * SMS/MMS/WhatsApp drivers only understand phone numbers. The send() contract is typed to the
 * generic AddressInterface (so the email driver can accept EmailAddress), which means each
 * phone-based driver must narrow the address back down explicitly and fail loudly when handed
 * an email address instead of silently producing a garbage API call.
 */
trait ExpectsPhoneNumbers
{
    /**
     * @throws TextoSendFailedException when the address is not a phone number
     */
    protected function assertPhoneNumber(AddressInterface $address, string $driverName, string $field): PhoneNumber
    {
        if (! $address instanceof PhoneNumber) {
            throw new TextoSendFailedException(
                "{$driverName} driver requires a phone number for '{$field}', got ".$address::class.' ('.$address->value().').'
            );
        }

        return $address;
    }
}
