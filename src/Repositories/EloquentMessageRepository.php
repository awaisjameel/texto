<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Repositories;

use Awaisjameel\Texto\Contracts\MessageRepositoryInterface;
use Awaisjameel\Texto\Enums\MessageStatus;
use Awaisjameel\Texto\Models\Message;
use Awaisjameel\Texto\ValueObjects\SentMessageResult;
use Awaisjameel\Texto\ValueObjects\WebhookProcessingResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EloquentMessageRepository implements MessageRepositoryInterface
{
    public function storeSent(SentMessageResult $result): Message
    {
        // Extract optional analytical fields from metadata (currently Telnyx-specific)
        $segments = $result->metadata['telnyx_parts'] ?? null;
        $cost = $result->metadata['telnyx_cost_amount'] ?? null;
        $cost = is_string($cost) ? $cost : null;

        // Ensure polling metadata defaults
        $baseMeta = $result->metadata;
        if (! array_key_exists('poll_attempts', $baseMeta)) {
            $baseMeta['poll_attempts'] = 0;
        }
        if (! array_key_exists('last_poll_at', $baseMeta)) {
            $baseMeta['last_poll_at'] = null;
        }

        $record = Message::create([
            'direction' => $result->direction->value,
            'driver' => $result->driver->value,
            'from_number' => $result->from?->value(),
            'to_number' => $result->to->value(),
            'body' => $result->body,
            'media_urls' => $result->mediaUrls,
            'status' => $result->status->value,
            'provider_message_id' => $result->providerMessageId,
            'error_code' => $result->errorCode,
            'metadata' => $baseMeta,
            'segments_count' => $segments,
            'cost_estimate' => $cost,
            'sent_at' => now(),
        ]);
        Log::debug('Texto stored sent message', ['id' => $record->id, 'provider_id' => $record->provider_message_id]);

        return $record;
    }

    public function storeInbound(WebhookProcessingResult $result): Message
    {
        $attributes = [
            'direction' => $result->direction->value,
            'driver' => $result->driver->value,
            'from_number' => $result->from?->value(),
            'to_number' => $result->to?->value(),
            'body' => $result->body,
            'media_urls' => $result->mediaUrls,
            'status' => ($result->status ? $result->status->value : MessageStatus::Received->value),
            'provider_message_id' => $result->providerMessageId,
            'metadata' => $result->metadata,
            'received_at' => now(),
        ];

        // Meta can retry the same webhook delivery. Its WAMID is stable, so use it as the
        // idempotency key and only emit an inbound event for a newly-created local record.
        // A short atomic-cache lock also prevents the first concurrent deliveries from racing
        // each other. Production deployments should use their normal shared cache (for example,
        // Redis) when they run multiple application nodes.
        $key = 'texto:inbound:'.hash('sha256', $result->driver->value."\0".$result->providerMessageId);
        $record = Cache::lock($key, 10)->block(2, function () use ($result, $attributes): Message {
            return Message::firstOrCreate([
                'driver' => $result->driver->value,
                'provider_message_id' => $result->providerMessageId,
            ], $attributes);
        });
        Log::debug('Texto stored inbound message', [
            'id' => $record->id,
            'provider_id' => $record->provider_message_id,
            'created' => $record->wasRecentlyCreated,
        ]);

        return $record;
    }

    public function storeStatus(WebhookProcessingResult $result): ?Message
    {
        if (! $result->providerMessageId) {
            return null;
        }
        $message = Message::where('driver', $result->driver->value)
            ->where('provider_message_id', $result->providerMessageId)
            ->first();
        if (! $message) {
            return null;
        }

        $previousStatus = $message->status;

        // Always merge metadata; status webhooks may carry useful event/error/recipient detail
        // even when the status itself does not advance (e.g. out-of-order or duplicate callbacks).
        $message->metadata = array_merge($message->metadata ?? [], $result->metadata);

        // Forward-only progression: never regress a terminal/more-advanced state. Providers deliver
        // delivery receipts out of order, so a late 'sent' must not overwrite 'delivered'.
        $progressed = false;
        if ($result->status !== null) {
            // The status column is nullable, so a row may carry no (or an unrecognized) status yet.
            $current = $message->status !== null ? MessageStatus::tryFrom($message->status) : null;
            if ($current === null || $current->progressesTo($result->status)) {
                $message->status = $result->status->value;
                $message->status_updated_at = now();
                $progressed = true;
            }
        }

        $message->save();
        Log::debug('Texto webhook status processed', [
            'id' => $message->id,
            'status' => $message->status,
            'previous' => $previousStatus,
            'progressed' => $progressed,
        ]);

        // Only signal an update (→ MessageStatusUpdated event) when the status actually advanced.
        return $progressed ? $message : null;
    }

    /**
     * Update status via polling (non-webhook). Increments poll_attempts and sets last_poll_at.
     */
    public function updatePolledStatus(Message $message, MessageStatus $status, array $extraMetadata = []): Message
    {
        $previousStatus = $message->status;
        $message->status = $status->value;
        $meta = $message->metadata ?? [];
        if (! isset($meta['poll_attempts'])) {
            $meta['poll_attempts'] = 0;
        }
        $meta['poll_attempts']++;
        $meta['last_poll_at'] = now()->toIso8601String();
        foreach ($extraMetadata as $k => $v) {
            $meta[$k] = $v;
        }
        $message->metadata = $meta;
        $message->status_updated_at = now();
        $message->save();
        Log::debug('Texto polled status update', [
            'id' => $message->id,
            'status' => $message->status,
            'previous' => $previousStatus,
            'poll_attempts' => $meta['poll_attempts'],
        ]);

        return $message;
    }

    /**
     * Upgrade a specific queued message by its primary key. This removes ambiguity when multiple queued rows share identical body/to/driver.
     */
    public function upgradeQueued(int $id, SentMessageResult $result): ?Message
    {
        $message = Message::find($id);
        if (! $message) {
            return null;
        }
        if (! in_array($message->status, [MessageStatus::Queued->value, MessageStatus::Ambiguous->value], true)) {
            // Only upgrade queued/ambiguous rows. If already upgraded, skip silently.
            Log::debug('Texto upgradeQueuedById skipped non-queued row', ['id' => $id, 'status' => $message->status]);

            return null;
        }

        $previousStatus = $message->status;
        $message->status = $result->status->value;
        $message->provider_message_id = $result->providerMessageId;
        $message->error_code = $result->errorCode;
        $merged = array_merge($message->metadata ?? [], $result->metadata);
        if (! array_key_exists('poll_attempts', $merged)) {
            $merged['poll_attempts'] = 0;
        }
        if (! array_key_exists('last_poll_at', $merged)) {
            $merged['last_poll_at'] = null;
        }
        $message->metadata = $merged;
        if (isset($result->metadata['telnyx_parts'])) {
            $message->segments_count = $result->metadata['telnyx_parts'];
        }
        if (isset($result->metadata['telnyx_cost_amount'])) {
            $message->cost_estimate = $result->metadata['telnyx_cost_amount'];
        }
        if (! $message->sent_at) {
            $message->sent_at = now();
        }
        $message->status_updated_at = now();
        $message->save();
        Log::debug('Texto upgraded queued message (by id)', [
            'id' => $message->id,
            'provider_id' => $message->provider_message_id,
            'status' => $message->status,
            'previous' => $previousStatus,
            'deterministic' => true,
        ]);

        return $message;
    }
}
