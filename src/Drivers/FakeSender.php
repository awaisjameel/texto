<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Drivers;

use Awaisjameel\Texto\Contracts\MessageSenderInterface;
use Awaisjameel\Texto\Enums\Direction;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Enums\MessageStatus;
use Awaisjameel\Texto\ValueObjects\PhoneNumber;
use Awaisjameel\Texto\ValueObjects\SentMessageResult;

/**
 * Simple fake sender for tests without hitting external APIs.
 */
class FakeSender implements MessageSenderInterface
{
    public function send(PhoneNumber $to, string $body, ?PhoneNumber $from = null, array $mediaUrls = [], array $metadata = []): SentMessageResult
    {
        return new SentMessageResult(
            Driver::Twilio, // reuse existing enum for simplicity
            Direction::Sent,
            $to,
            $from ?? PhoneNumber::fromString('+10000000000'),
            $body,
            $mediaUrls,
            $metadata,
            MessageStatus::Sent,
            'fake-'.uniqid(),
        );
    }
}
