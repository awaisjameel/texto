<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\ValueObjects;

use Awaisjameel\Texto\Enums\Direction;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Enums\MessageStatus;

final class WebhookProcessingResult
{
    /** @param string[] $mediaUrls */
    private function __construct(
        public readonly Driver $driver,
        public readonly Direction $direction,
        public readonly ?PhoneNumber $from,
        public readonly ?PhoneNumber $to,
        public readonly ?string $body,
        public readonly array $mediaUrls,
        public readonly array $metadata,
        public readonly ?string $providerMessageId,
        public readonly ?MessageStatus $status,
    ) {}

    /** @param string[] $media */
    public static function inbound(Driver $driver, PhoneNumber $from, PhoneNumber $to, ?string $body, array $media, array $metadata, ?string $providerMessageId = null): self
    {
        return new self($driver, Direction::Received, $from, $to, $body, $media, $metadata, $providerMessageId, MessageStatus::Received);
    }

    public static function status(Driver $driver, ?string $providerMessageId, MessageStatus $status, array $metadata = []): self
    {
        return new self($driver, Direction::Sent, null, null, null, [], $metadata, $providerMessageId, $status);
    }
}
