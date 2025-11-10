<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Contracts;

use Awaisjameel\Texto\Enums\MessageStatus;

/**
 * Implemented by drivers that support polling provider APIs for delivery updates.
 */
interface PollableMessageSenderInterface
{
    /**
     * Fetch the most recent status for a previously sent message.
     *
     * @param  string  $providerMessageId  Provider identifier (SID, GUID, etc.)
     * @param  mixed  ...$context  Optional driver-specific context (conversation SID, etc.)
     */
    public function fetchStatus(string $providerMessageId, mixed ...$context): ?MessageStatus;
}
