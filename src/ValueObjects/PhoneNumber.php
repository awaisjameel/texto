<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\ValueObjects;

use Awaisjameel\Texto\Contracts\AddressInterface;
use Awaisjameel\Texto\Exceptions\TextoException;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

final class PhoneNumber implements AddressInterface
{
    /**
     * @param  string  $e164  The canonical sender/recipient address. Normally an E.164 number,
     *                        but may also hold an alphanumeric sender ID (e.g. "Acme") when used
     *                        as a `from` value — those are valid Twilio/Telnyx senders, not numbers.
     */
    public function __construct(public readonly string $e164) {}

    public static function fromString(string $raw, ?string $region = null): self
    {
        $raw = trim($raw);
        if ($raw === '') {
            throw new TextoException('Empty phone number.');
        }

        // Alphanumeric sender IDs are valid Twilio/Telnyx `from` values but are not phone numbers,
        // so they must bypass libphonenumber (which would reject them) and be kept verbatim.
        if (self::isAlphanumericSenderId($raw)) {
            return new self($raw);
        }

        // Everything else goes through libphonenumber for consistent parsing/normalisation — no raw
        // regex fast-path that would let unparsed strings through unchecked. We gate on
        // isPossibleNumber rather than isValidNumber so structurally valid senders that
        // isValidNumber rejects (short codes, region-unassigned ranges, fictional test numbers)
        // are still accepted, while genuinely malformed input is rejected.
        $util = PhoneNumberUtil::getInstance();
        $region = $region ?: config('texto.validation.region', 'US');
        try {
            $proto = $util->parse($raw, $region);
            if (! $util->isPossibleNumber($proto)) {
                throw new TextoException('Invalid phone number: '.$raw);
            }

            return new self($util->format($proto, PhoneNumberFormat::E164));
        } catch (NumberParseException $e) {
            throw new TextoException('Unable to parse phone number: '.$raw.' message: '.$e->getMessage());
        }
    }

    /**
     * Detect an alphanumeric sender ID per Twilio/Telnyx rules: 1–11 characters of letters, digits
     * and spaces, containing at least one letter (a pure number is a phone number, not a sender ID).
     */
    private static function isAlphanumericSenderId(string $value): bool
    {
        return (bool) preg_match('/^(?=.*[A-Za-z])[A-Za-z0-9 ]{1,11}$/', $value);
    }

    public function value(): string
    {
        return $this->e164;
    }

    public function __toString(): string
    {
        return $this->e164;
    }
}
