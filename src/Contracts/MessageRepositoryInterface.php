<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Contracts;

use Awaisjameel\Texto\Enums\MessageStatus;
use Awaisjameel\Texto\Models\Message;
use Awaisjameel\Texto\ValueObjects\SentMessageResult;
use Awaisjameel\Texto\ValueObjects\WebhookProcessingResult;

interface MessageRepositoryInterface
{
    public function storeSent(SentMessageResult $result): Message;

    public function storeInbound(WebhookProcessingResult $result): Message;

    public function storeStatus(WebhookProcessingResult $result): ?Message;

    /**
     * Update status via polling fallback.
     *
     * @param  array<string, mixed>  $extraMetadata
     */
    public function updatePolledStatus(Message $message, MessageStatus $status, array $extraMetadata = []): Message;

    /**
     * Upgrade a specific queued message by its primary key.
     *
     * @param  int  $id  Message ID
     * @param  SentMessageResult  $result  Updated result
     */
    public function upgradeQueued(int $id, SentMessageResult $result): ?Message;
}
