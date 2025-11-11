<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Contracts;

/**
 * Adapter contract for Twilio Messaging REST API operations (SMS/MMS).
 */
interface TwilioMessagingApiInterface
{
    /**
     * Send an outbound SMS/MMS message.
     * \n Parameters map to Twilio Messages API form fields.
     * @param string $to E.164 recipient
     * @param string $from E.164 sender (or Messaging Service SID)
     * @param string|null $body Optional text body (required if no media/content template)
     * @param array<string> $mediaUrls Array of media URLs (max 10)
     * @param array<string,mixed> $options Additional API parameters (StatusCallback, SmartEncoded, etc.)
     * @return array Raw decoded JSON (or associative array) response
     */
    public function sendMessage(string $to, string $from, ?string $body, array $mediaUrls = [], array $options = []): array;

    /**
     * Fetch a message resource by SID.
     * @param string $messageSid SM/ MM SID
     * @return array Raw decoded JSON
     */
    public function fetchMessage(string $messageSid): array;
}
