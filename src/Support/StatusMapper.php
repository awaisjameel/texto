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
            'accepted', 'sending', 'receiving' => MessageStatus::Sending,
            'sent', 'submitted', 'delivery_unknown' => MessageStatus::Sent,
            'delivered', 'read' => MessageStatus::Delivered,
            'failed', 'delivery_failed' => MessageStatus::Failed,
            'undelivered' => MessageStatus::Undelivered,
            'received' => MessageStatus::Received,
            default => MessageStatus::Sent,
        };
    }

    private static function mapTelnyx(?string $rawStatus, ?string $eventType): MessageStatus
    {
        // Prefer explicit event type mapping (from status webhook) then fallback to per-recipient raw status.
        if ($eventType) {
            $et = strtolower($eventType);

            return match ($et) {
                'message.queued', 'message.delivery_status.queued' => MessageStatus::Queued,
                'message.sending', 'message.delivery_status.sending' => MessageStatus::Sending,
                'message.sent', 'message.delivery_status.sent' => MessageStatus::Sent,
                'message.delivered', 'message.delivery_status.delivered', 'message.delivery_status.read' => MessageStatus::Delivered,
                'message.failed', 'message.canceled', 'message.delivery_status.failed', 'message.delivery_status.undelivered' => MessageStatus::Failed,
                'message.received' => MessageStatus::Received,
                default => MessageStatus::Sent,
            };
        }
        if ($rawStatus) {
            return match (strtolower($rawStatus)) {
                'queued' => MessageStatus::Queued,
                'sending' => MessageStatus::Sending,
                'accepted' => MessageStatus::Sending,
                'sent' => MessageStatus::Sent,
                'delivered' => MessageStatus::Delivered,
                'read' => MessageStatus::Delivered,
                'failed' => MessageStatus::Failed,
                'undelivered' => MessageStatus::Undelivered,
                default => MessageStatus::Sent,
            };
        }

        return MessageStatus::Queued; // conservative default for Telnyx initial API responses
    }
}
