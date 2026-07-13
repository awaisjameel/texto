<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Enums;

enum MessageStatus: string
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Received = 'received';
    case Failed = 'failed';
    case Undelivered = 'undelivered';
    case Ambiguous = 'ambiguous'; // provider id missing after polling attempts

    /**
     * Lifecycle rank used to enforce forward-only progression.
     * Higher ranks are "more advanced"; terminal states share the top rank.
     * Shared by webhook persistence and the polling job so they never diverge.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Ambiguous => 0,
            self::Queued => 1,
            self::Sending => 2,
            self::Sent => 3,
            self::Delivered, self::Failed, self::Undelivered, self::Received => 4,
            self::Read => 5,
        };
    }

    /** Whether this is a final, non-advancing delivery state. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Read, self::Failed, self::Undelivered => true,
            default => false,
        };
    }

    /**
     * Decide whether moving to $next is a forward progression from this status.
     * Terminal states are never overwritten by another (non-equal) status.
     */
    public function progressesTo(self $next): bool
    {
        if ($this->isTerminal()) {
            return false;
        }

        return $next->rank() > $this->rank();
    }
}
