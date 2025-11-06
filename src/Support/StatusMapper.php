<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Support;

use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Enums\MessageStatus;

/**
 * Centralized mapping of provider raw statuses / event types to internal MessageStatus enum.
 * Keeps senders & webhook handlers lean and consistent.
 */
final class StatusMapper
{
    /**
     * Generic mapping entry point. Optionally pass eventType (for Telnyx) or raw status.
     */
    public static function map(Driver $driver, ?string $rawStatus, ?string $eventType = null): MessageStatus
    {
        return match ($driver) {
            Driver::Twilio => self::mapTwilio($rawStatus ?? $eventType),
            Driver::Telnyx => self::mapTelnyx($rawStatus, $eventType),
        };
    }

    private static function mapTwilio(?string $status): MessageStatus
    {
        if (! $status) {
            return MessageStatus::Sent; // Twilio often implies 'sent' if missing
        }

        return match (strtolower($status)) {
            'queued' => MessageStatus::Queued,
            'sending' => MessageStatus::Sending,
            'sent' => MessageStatus::Sent,
            'delivered' => MessageStatus::Delivered,
            'failed' => MessageStatus::Failed,
            'undelivered' => MessageStatus::Undelivered,
            default => MessageStatus::Sent,
        };
    }

    private static function mapTelnyx(?string $rawStatus, ?string $eventType): MessageStatus
    {
        // Prefer explicit event type mapping (from status webhook) then fallback to per-recipient raw status.
        if ($eventType) {
            $et = strtolower($eventType);

            return match ($et) {
                'message.queued' => MessageStatus::Queued,
                'message.sending' => MessageStatus::Sending,
                'message.sent' => MessageStatus::Sent,
                'message.delivered' => MessageStatus::Delivered,
                'message.failed', 'message.canceled' => MessageStatus::Failed,
                default => MessageStatus::Sent,
            };
        }
        if ($rawStatus) {
            return match (strtolower($rawStatus)) {
                'queued' => MessageStatus::Queued,
                'sending' => MessageStatus::Sending,
                'sent' => MessageStatus::Sent,
                'delivered' => MessageStatus::Delivered,
                'failed' => MessageStatus::Failed,
                'undelivered' => MessageStatus::Undelivered,
                default => MessageStatus::Sent,
            };
        }

        return MessageStatus::Queued; // conservative default for Telnyx initial API responses
    }
}
