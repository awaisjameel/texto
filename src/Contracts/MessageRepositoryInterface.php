<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Contracts;

use Awaisjameel\Texto\ValueObjects\SentMessageResult;
use Awaisjameel\Texto\ValueObjects\WebhookProcessingResult;
use Illuminate\Database\Eloquent\Model;

interface MessageRepositoryInterface
{
    public function storeSent(SentMessageResult $result): Model;

    public function storeInbound(WebhookProcessingResult $result): Model;

    public function storeStatus(WebhookProcessingResult $result): ?Model;

    /** Update status via polling fallback */
    public function updatePolledStatus(\Awaisjameel\Texto\Models\Message $message, \Awaisjameel\Texto\Enums\MessageStatus $status, array $extraMetadata = []): \Awaisjameel\Texto\Models\Message;

    /**
     * Deterministically upgrade a queued message by its primary key.
     */
    public function upgradeQueued(int $id, SentMessageResult $result): ?Model;
}
