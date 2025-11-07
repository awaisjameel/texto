<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Contracts;

use Awaisjameel\Texto\ValueObjects\PhoneNumber;
use Awaisjameel\Texto\ValueObjects\SentMessageResult;

interface MessageSenderInterface
{
    /**
     * Send an SMS/MMS message.
     *
     * @param  PhoneNumber  $to  Recipient phone number
     * @param  string  $body  Message body text
     * @param  PhoneNumber|null  $from  Sender phone number (optional)
     * @param  string[]  $mediaUrls  Array of media URLs for MMS
     * @param  array<string, mixed>  $metadata  Additional metadata
     */
    public function send(PhoneNumber $to, string $body, ?PhoneNumber $from = null, array $mediaUrls = [], array $metadata = []): SentMessageResult;
}
