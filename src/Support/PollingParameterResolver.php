<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Support;

use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Models\Message;

/**
 * Centralized resolution of fetchStatus parameter list per driver.
 * This allows drivers to evolve their polling signatures without modifying core job logic.
 */
class PollingParameterResolver
{
    /**
     * Return ordered argument list to pass to fetchStatus for the given driver and message.
     * First element is always provider message id; subsequent optional elements are driver-specific.
     *
     * @return array<int, mixed>
     */
    public static function fetchStatusArgs(Driver $driver, Message $message): array
    {
        $providerId = $message->provider_message_id;
        if (! $providerId) {
            return []; // caller should already have guarded against missing provider id
        }

        $meta = $message->metadata ?? [];

        return match ($driver) {
            Driver::Twilio => self::twilioArgs($providerId, $meta),
            default => [$providerId], // Telnyx and others currently only require provider id
        };
    }

    /**
     * Twilio fetchStatus expects provider SID + optional conversation SID.
     *
     * @param  array<string, mixed>  $meta
     * @return array<int, mixed>
     */
    protected static function twilioArgs(string $providerId, array $meta): array
    {
        $conversationSid = $meta['conversation_sid'] ?? null;
        if ($conversationSid) {
            return [$providerId, $conversationSid];
        }

        return [$providerId];
    }
}
