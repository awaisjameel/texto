<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Contracts;

/** Optional capability for adapters that expose individual Conversations receipts. */
interface TwilioDeliveryReceiptsApiInterface
{
    /** @return list<array<string, mixed>> */
    public function fetchDeliveryReceipts(string $conversationSid, string $messageSid): array;
}
