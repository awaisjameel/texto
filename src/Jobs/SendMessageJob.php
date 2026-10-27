<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Jobs;

use Awaisjameel\Texto\Enums\MessageStatus;
use Awaisjameel\Texto\Models\Message;
use Awaisjameel\Texto\Texto;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendMessageJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Providers without idempotency keys must not be retried after an uncertain worker failure.
    public int $tries = 1;

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return $this->messageId > 0
            ? [(new WithoutOverlapping('texto-send:'.$this->messageId))->dontRelease()->expireAfter(180)]
            : [];
    }

    /** @param array{from?:string|null, media_urls?:string[], metadata?:array<string,mixed>, driver?:string, driver_config?:array<string,mixed>} $options */
    public function __construct(
        public int $messageId,
        public string $to,
        public string $body,
        public array $options = []
    ) {}

    public function handle(Texto $texto): void
    {
        if ($this->messageId > 0) {
            $record = Message::find($this->messageId);
            if ($record !== null && ! in_array($record->status, [MessageStatus::Queued->value, MessageStatus::Ambiguous->value], true)) {
                return;
            }
        }
        Log::info('Texto SendMessageJob handling', [
            'message_id' => $this->messageId,
            'to' => $this->to,
            'has_media' => ! empty($this->options['media_urls']),
            'driver' => $this->options['driver'] ?? null,
        ]);
        // Pass the queued message id so Texto upgrades the exact record instead of pattern matching.
        $result = $texto->send($this->to, $this->body, $this->options + [
            'queued_job' => true,
            'queued_message_id' => $this->messageId,
        ]);
        Log::info('Texto SendMessageJob completed', [
            'message_id' => $this->messageId,
            'to' => $this->to,
            'provider_id' => $result->providerMessageId,
            'status' => $result->status->name,
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        if ($this->messageId < 1) {
            return;
        }
        $record = Message::find($this->messageId);
        if ($record !== null && $record->provider_message_id === null
            && in_array($record->status, [MessageStatus::Queued->value, MessageStatus::Sending->value], true)) {
            $record->status = MessageStatus::Ambiguous->value;
            $record->error_code = 'queue_job_failed';
            $record->metadata = array_merge($record->metadata ?? [], ['queue_failure' => $exception !== null ? $exception::class : 'unknown']);
            $record->save();
        }
    }
}
