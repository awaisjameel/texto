<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Contracts;

use Awaisjameel\Texto\ValueObjects\SentMessageResult;

interface MessageSenderInterface
{
    /**
     * Send a message (SMS/MMS/WhatsApp/Email depending on the driver).
     *
     * Drivers that only support one address family (e.g. SMS drivers require
     * phone numbers, the email driver requires email addresses) must throw a
     * TextoSendFailedException when given an incompatible address.
     *
     * @param  AddressInterface  $to  Recipient address (phone number or email address)
     * @param  string  $body  Message body text (plain text)
     * @param  AddressInterface|null  $from  Sender address (optional)
     * @param  string[]  $mediaUrls  Array of media URLs (MMS media / email attachments)
     * @param  array<string, mixed>  $metadata  Additional metadata
     */
    public function send(AddressInterface $to, string $body, ?AddressInterface $from = null, array $mediaUrls = [], array $metadata = []): SentMessageResult;
}
