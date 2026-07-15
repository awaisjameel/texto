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
            Driver::Whatsapp => self::mapWhatsapp($rawStatus),
            Driver::Email => self::mapEmail($rawStatus ?? $eventType),
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
        // The per-recipient delivery status (to[].status) is Telnyx's source of truth. Telnyx's
        // real DLR event is `message.finalized`, which only carries the final state inside that
        // recipient status (delivered / delivery_failed) — so the raw status must win over the
        // event type. The event type is used only as a fallback when no raw status is present.
        if ($rawStatus !== null) {
            $mapped = self::mapTelnyxRawStatus($rawStatus);
            if ($mapped !== null) {
                return $mapped;
            }
        }

        if ($eventType !== null) {
            $mapped = self::mapTelnyxEventType($eventType);
            if ($mapped !== null) {
                return $mapped;
            }
        }

        return MessageStatus::Queued; // conservative default for Telnyx initial API responses
    }

    /**
     * Map a Telnyx per-recipient status string. Returns null for unrecognized values so the
     * caller can fall back to the event type.
     */
    private static function mapTelnyxRawStatus(string $rawStatus): ?MessageStatus
    {
        return match (strtolower($rawStatus)) {
            'queued', 'queued_canceled' => MessageStatus::Queued,
            'sending', 'accepted' => MessageStatus::Sending,
            'sent' => MessageStatus::Sent,
            'delivered', 'read', 'webhook_delivered' => MessageStatus::Delivered,
            'delivery_failed', 'sending_failed', 'failed', 'expired', 'rejected' => MessageStatus::Failed,
            'undelivered', 'delivery_unconfirmed' => MessageStatus::Undelivered,
            default => null,
        };
    }

    /**
     * Map a Telnyx webhook event type. `message.finalized` is intentionally not mapped here:
     * its outcome lives in the per-recipient status, which is handled before this is consulted.
     * Returns null for unrecognized event types.
     */
    private static function mapTelnyxEventType(string $eventType): ?MessageStatus
    {
        return match (strtolower($eventType)) {
            'message.received' => MessageStatus::Received,
            'message.queued' => MessageStatus::Queued,
            'message.sending' => MessageStatus::Sending,
            'message.sent' => MessageStatus::Sent,
            default => null,
        };
    }

    /**
     * Email providers use a shared vocabulary for delivery events (SES/SendGrid/Mailgun/
     * Postmark/Resend all converge on these terms). Notable choices:
     * - opened/clicked map to Read (the email analogue of a read receipt);
     * - complaint maps to Delivered — the message DID reach the mailbox; the complaint
     *   itself is preserved in metadata.raw_status for the application to act on;
     * - deferred is a transient retry state, not a failure.
     */
    private static function mapEmail(?string $status): MessageStatus
    {
        if (! $status) {
            return MessageStatus::Sent;
        }

        return match (strtolower($status)) {
            'queued', 'accepted' => MessageStatus::Queued,
            'sending', 'deferred', 'delayed' => MessageStatus::Sending,
            'sent', 'processed', 'delivery_unknown' => MessageStatus::Sent,
            'delivered', 'delivery' => MessageStatus::Delivered,
            'opened', 'open', 'clicked', 'click', 'read' => MessageStatus::Read,
            'complained', 'complaint', 'spam' => MessageStatus::Delivered,
            'bounced', 'bounce', 'hard_bounce', 'permanent_fail', 'failed', 'dropped', 'rejected', 'suppressed' => MessageStatus::Failed,
            'soft_bounce', 'temporary_fail', 'undelivered' => MessageStatus::Undelivered,
            'received', 'inbound' => MessageStatus::Received,
            default => MessageStatus::Sent,
        };
    }

    private static function mapWhatsapp(?string $status): MessageStatus
    {
        if (! $status) {
            return MessageStatus::Sent;
        }

        return match (strtolower($status)) {
            'accepted', 'held_for_quality_assessment' => MessageStatus::Sending,
            'sent' => MessageStatus::Sent,
            'delivered' => MessageStatus::Delivered,
            'read' => MessageStatus::Read,
            'failed' => MessageStatus::Failed,
            'deleted' => MessageStatus::Undelivered,
            default => MessageStatus::Sent,
        };
    }
}
