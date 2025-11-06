<?php

use Awaisjameel\Texto\Contracts\MessageRepositoryInterface;
use Awaisjameel\Texto\Enums\Direction;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Enums\MessageStatus;
use Awaisjameel\Texto\ValueObjects\PhoneNumber;
use Awaisjameel\Texto\ValueObjects\SentMessageResult;

it('upgrades only the targeted queued message when duplicates exist', function () {
    /** @var MessageRepositoryInterface $repo */
    $repo = app(MessageRepositoryInterface::class);

    $body = 'Duplicate body '.now()->format('Y-m-d H:i:s');
    $to = PhoneNumber::fromString('+12345678901');
    $from = PhoneNumber::fromString('+19876543210');

    // Two queued results with identical attributes (simulate rapid successive sends)
    $queued1 = new SentMessageResult(Driver::Telnyx, Direction::Sent, $to, $from, $body, [], [], MessageStatus::Queued, null);
    $queued2 = new SentMessageResult(Driver::Telnyx, Direction::Sent, $to, $from, $body, [], [], MessageStatus::Queued, null);

    $record1 = $repo->storeSent($queued1);
    $record2 = $repo->storeSent($queued2);

    // Final send result for the SECOND message only
    $final2 = new SentMessageResult(Driver::Telnyx, Direction::Sent, $to, $from, $body, [], [], MessageStatus::Queued, 'PID-SECOND');

    $repo->upgradeQueued($record2->id, $final2);

    $record1->refresh();
    $record2->refresh();

    expect($record1->provider_message_id)->toBeNull();
    expect($record2->provider_message_id)->toBe('PID-SECOND');
    expect($record1->id)->not()->toEqual($record2->id);
});
