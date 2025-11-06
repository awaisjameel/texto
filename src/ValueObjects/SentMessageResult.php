<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\ValueObjects;

use Awaisjameel\Texto\Enums\Direction;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Enums\MessageStatus;

/**
 * Value object returned after a message send attempt.
 * Implements Responsable, JsonSerializable & Stringable so it can be
 * returned directly from a route/controller.
 */
final class SentMessageResult implements \Illuminate\Contracts\Support\Responsable, \JsonSerializable, \Stringable
{
    /** @param string[] $mediaUrls */
    public function __construct(
        public readonly Driver $driver,
        public readonly Direction $direction,
        public readonly PhoneNumber $to,
        public readonly ?PhoneNumber $from,
        public readonly string $body,
        /** @var string[] */
        public readonly array $mediaUrls,
        /** Arbitrary metadata captured during send */
        public readonly array $metadata,
        public readonly MessageStatus $status,
        /** Provider-specific message identifier (Twilio SID, etc.) */
        public readonly ?string $providerMessageId = null,
        /** Optional provider error code if a partial failure occurred */
        public readonly ?string $errorCode = null,
    ) {}

    /** Structured array representation for logging / serialization. */
    public function toArray(): array
    {
        return [
            'driver' => $this->driver->name,
            'direction' => $this->direction->name,
            'to' => (string) $this->to,
            'from' => $this->from?->e164,
            'body' => $this->body,
            'media_urls' => $this->mediaUrls,
            'metadata' => $this->metadata,
            'status' => $this->status->name,
            'provider_message_id' => $this->providerMessageId,
            'error_code' => $this->errorCode,
        ];
    }

    /** {@inheritDoc} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** Stringable implementation: JSON encoded result. */
    public function __toString(): string
    {
        try {
            return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return 'SentMessageResult(driver='.$this->driver->name.', to='.(string) $this->to.')';
        }
    }

    /** Allow returning directly from routes/controllers. */
    public function toResponse($request): \Illuminate\Http\JsonResponse
    {
        return response()->json($this->toArray());
    }
}
