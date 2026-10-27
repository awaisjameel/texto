<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Support;

use Awaisjameel\Texto\Contracts\AddressInterface;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\ValueObjects\EmailAddress;
use Awaisjameel\Texto\ValueObjects\PhoneNumber;

final class AddressResolver
{
    public static function parse(string $raw): AddressInterface
    {
        return str_contains($raw, '@') ? EmailAddress::fromString($raw) : PhoneNumber::fromString($raw);
    }

    /** @param array<string, mixed> $configuration */
    public static function defaultFrom(Driver $driver, array $configuration): ?AddressInterface
    {
        $settings = new Configuration($configuration);
        if ($driver !== Driver::Email) {
            $raw = $settings->text('from_number');

            return $raw === '' ? null : PhoneNumber::fromString($raw);
        }
        $raw = $settings->text('from_address');
        $name = $settings->text('from_name');
        if ($raw === '') {
            $appFrom = config('mail.from', []);
            $app = new Configuration(is_array($appFrom) ? $appFrom : []);
            $raw = $app->text('address');
            $name = $app->text('name');
        }
        if ($raw === '') {
            return null;
        }
        $parsed = EmailAddress::fromString($raw);

        return new EmailAddress($parsed->address, $name !== '' ? $name : $parsed->displayName);
    }
}
