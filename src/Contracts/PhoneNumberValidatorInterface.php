<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Contracts;

use Awaisjameel\Texto\ValueObjects\PhoneNumber;

interface PhoneNumberValidatorInterface
{
    public function validate(string $number, ?string $region = null): PhoneNumber;
}
