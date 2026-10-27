<?php

declare(strict_types=1);

use Awaisjameel\Texto\Contracts\DriverManagerInterface;
use Awaisjameel\Texto\Contracts\MessageRepositoryInterface;
use Awaisjameel\Texto\Jobs\StatusPollJob;
use Awaisjameel\Texto\Models\Message;
use Illuminate\Support\Facades\Http;

it('polls an eligible newer message even when the oldest rows have exhausted their attempts', function (): void {
    config()->set('texto.status_polling', ['enabled' => true, 'min_age_seconds' => 0, 'max_attempts' => 5, 'queued_max_attempts' => 2, 'backoff_seconds' => 0, 'batch_limit' => 1]);
    config()->set('texto.telnyx.api_key', 'poll-key');
    Message::create(['driver' => 'telnyx', 'direction' => 'sent', 'status' => 'sent', 'provider_message_id' => 'exhausted', 'metadata' => ['poll_attempts' => 5]]);
    $eligible = Message::create(['driver' => 'telnyx', 'direction' => 'sent', 'status' => 'sent', 'provider_message_id' => 'eligible', 'metadata' => []]);
    Http::fake(['api.telnyx.com/v2/messages/eligible' => Http::response(['data' => ['id' => 'eligible', 'to' => [['status' => 'delivered']]]])]);
    (new StatusPollJob)->handle(app(MessageRepositoryInterface::class), app(DriverManagerInterface::class));
    expect($eligible->fresh()->status)->toBe('delivered');
    Http::assertSentCount(1);
});
