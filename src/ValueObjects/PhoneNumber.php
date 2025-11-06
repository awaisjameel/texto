<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\ValueObjects;

use Awaisjameel\Texto\Exceptions\TextoException;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;

final class PhoneNumber
{
    public function __construct(public readonly string $e164) {}

    public static function fromString(string $raw, ?string $region = null): self
    {
        $raw = trim($raw);
        if ($raw === '') {
            throw new TextoException('Empty phone number.');
        }
        // If already looks E.164 just basic sanity
        if (preg_match('/^\+\d{8,15}$/', $raw)) {
            return new self($raw);
        }
        $util = PhoneNumberUtil::getInstance();
        $region = $region ?: config('texto.validation.region', 'US');
        try {
            $proto = $util->parse($raw, $region);
            if (! $util->isValidNumber($proto)) {
                throw new TextoException('Invalid phone number: '.$raw);
            }

            return new self($util->format($proto, \libphonenumber\PhoneNumberFormat::E164));
        } catch (NumberParseException $e) {
            throw new TextoException('Unable to parse phone number: '.$raw.' message: '.$e->getMessage());
        }
    }

    public function __toString(): string
    {
        return $this->e164;
    }
}
