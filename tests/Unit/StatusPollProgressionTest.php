<?php

declare(strict_types=1);

use Awaisjameel\Texto\Contracts\DriverManagerInterface;
use Awaisjameel\Texto\Contracts\MessageRepositoryInterface;
use Awaisjameel\Texto\Contracts\MessageSenderInterface;
use Awaisjameel\Texto\Enums\Direction;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Enums\MessageStatus;
use Awaisjameel\Texto\Jobs\StatusPollJob;
use Awaisjameel\Texto\Models\Message;
use Awaisjameel\Texto\ValueObjects\PhoneNumber;
use Awaisjameel\Texto\ValueObjects\SentMessageResult;

it('promotes queued to sent on polling when provider returns sent', function () {
    // Enable polling with zero age/backoff for test simplicity
    config(['texto.status_polling.enabled' => true]);
    config(['texto.status_polling.min_age_seconds' => 0]);
    config(['texto.status_polling.backoff_seconds' => 0]);

    // Fake driver manager that always returns a sender reporting Sent
    $this->app->bind(DriverManagerInterface::class, function () {
        return new class implements DriverManagerInterface
        {
            public function sender(?Driver $driver = null): MessageSenderInterface
            {
                return new class implements MessageSenderInterface
                {
                    public function send(PhoneNumber $to, string $body, ?PhoneNumber $from = null, array $mediaUrls = [], array $metadata = []): SentMessageResult
                    {
                        throw new RuntimeException('send not used in polling test');
                    }

                    public function fetchStatus(string $providerMessageId): ?MessageStatus
                    {
                        return MessageStatus::Sent; // Always promote to Sent
                    }
                };
            }

            public function extend(string $name, callable $factory): void {}
        };
    });

    // Create a queued message with provider id so fetchStatus runs
    $message = Message::create([
        'direction' => Direction::Sent->value,
        'driver' => Driver::Telnyx->value,
        'from_number' => '+10000000000',
        'to_number' => '+10000000001',
        'body' => 'test',
        'media_urls' => [],
        'status' => MessageStatus::Queued->value,
        'provider_message_id' => 'abc-123',
        'metadata' => ['poll_attempts' => 0, 'last_poll_at' => null],
    ]);

    // Run poll job
    $job = new StatusPollJob;
    $job->handle(app(MessageRepositoryInterface::class), app(DriverManagerInterface::class));

    $message->refresh();
    expect($message->status)->toBe(MessageStatus::Sent->value);
    expect(($message->metadata)['poll_promoted'] ?? null)->toBeTrue();
});
