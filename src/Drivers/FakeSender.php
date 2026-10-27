<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Drivers;

use Awaisjameel\Texto\Contracts\AddressInterface;
use Awaisjameel\Texto\Contracts\MessageSenderInterface;
use Awaisjameel\Texto\Enums\Direction;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Enums\MessageStatus;
use Awaisjameel\Texto\ValueObjects\EmailAddress;
use Awaisjameel\Texto\ValueObjects\PhoneNumber;
use Awaisjameel\Texto\ValueObjects\SentMessageResult;

/**
 * Simple fake sender for tests without hitting external APIs.
 */
class FakeSender implements MessageSenderInterface
{
    public function send(AddressInterface $to, string $body, ?AddressInterface $from = null, array $mediaUrls = [], array $metadata = []): SentMessageResult
    {
        $isEmail = $to instanceof EmailAddress;

        return new SentMessageResult(
            $isEmail ? Driver::Email : Driver::Twilio, // reuse existing enums for simplicity
            Direction::Sent,
            $to,
            $from ?? ($isEmail ? new EmailAddress('fake@example.com') : PhoneNumber::fromString('+10000000000')),
            $body,
            $mediaUrls,
            $metadata,
            MessageStatus::Sent,
            'fake-'.uniqid(),
        );
    }
}
