<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Jobs;

use Awaisjameel\Texto\Texto;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @param array{from?:string, media_urls?:string[], metadata?:array, driver?:string, driver_config?:array<string,mixed>} $options */
    public function __construct(
        public int $messageId,
        public string $to,
        public string $body,
        public array $options = []
    ) {}

    public function handle(Texto $texto): void
    {
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
}
