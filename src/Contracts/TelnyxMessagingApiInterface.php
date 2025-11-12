<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Contracts;

/**
 * Adapter contract for Telnyx Messaging REST API operations.
 */
interface TelnyxMessagingApiInterface
{
    /**
     * Send an outbound SMS/MMS message via Telnyx.
     * @param string $to E.164 recipient
     * @param string $from E.164 sender (must be configured on Telnyx)
     * @param string $body Message text (required unless using advanced features)
     * @param array<string> $mediaUrls Optional media URL list
     * @param array<string,mixed> $options Additional Telnyx parameters (messaging_profile_id, webhook_url, etc.)
     * @return array<string,mixed> Decoded Telnyx message resource (unwrapped 'data')
     */
    public function sendMessage(string $to, string $from, string $body, array $mediaUrls = [], array $options = []): array;

    /**
     * Fetch a Telnyx message by id.
     * @param string $messageId Telnyx message id
     * @return array<string,mixed> Decoded Telnyx message resource
     */
    public function fetchMessage(string $messageId): array;
}
