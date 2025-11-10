<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Contracts;

use Awaisjameel\Texto\ValueObjects\PhoneNumber;

interface PhoneNumberValidatorInterface
{
    /**
     * Validate and parse a phone number.
     *
     * @param  string  $number  Raw phone number string
     * @param  string|null  $region  Default region for parsing (e.g., 'US', 'GB')
     * @return PhoneNumber Validated phone number object
     */
    public function validate(string $number, ?string $region = null): PhoneNumber;
}
