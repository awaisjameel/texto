<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Contracts;

use Awaisjameel\Texto\ValueObjects\SentMessageResult;
use Awaisjameel\Texto\ValueObjects\WebhookProcessingResult;

interface MessageRepositoryInterface
{
    public function storeSent(SentMessageResult $result): \Awaisjameel\Texto\Models\Message;

    public function storeInbound(WebhookProcessingResult $result): \Awaisjameel\Texto\Models\Message;

    public function storeStatus(WebhookProcessingResult $result): ?\Awaisjameel\Texto\Models\Message;

    /**
     * Update status via polling fallback.
     *
     * @param  array<string, mixed>  $extraMetadata
     */
    public function updatePolledStatus(\Awaisjameel\Texto\Models\Message $message, \Awaisjameel\Texto\Enums\MessageStatus $status, array $extraMetadata = []): \Awaisjameel\Texto\Models\Message;

    /**
     * Upgrade a specific queued message by its primary key.
     *
     * @param  int  $id  Message ID
     * @param  SentMessageResult  $result  Updated result
     */
    public function upgradeQueued(int $id, SentMessageResult $result): ?\Awaisjameel\Texto\Models\Message;
}
