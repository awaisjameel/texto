<?php

use Awaisjameel\Texto\Contracts\MessageRepositoryInterface;
use Awaisjameel\Texto\Enums\Direction;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Enums\MessageStatus;
use Awaisjameel\Texto\ValueObjects\PhoneNumber;
use Awaisjameel\Texto\ValueObjects\SentMessageResult;

it('deterministically upgrades only targeted queued messages among identical rows', function () {
    /** @var MessageRepositoryInterface $repo */
    $repo = app(MessageRepositoryInterface::class);

    $body = 'Same body '.now()->format('Y-m-d H:i:s');
    $to = PhoneNumber::fromString('+12345670000');
    $from = PhoneNumber::fromString('+19876540000');

    // Create three queued messages with identical matching fields
    $queued = [];
    for ($i = 0; $i < 3; $i++) {
        $queuedResult = new SentMessageResult(Driver::Telnyx, Direction::Sent, $to, $from, $body, [], [], MessageStatus::Queued, null);
        $queued[] = $repo->storeSent($queuedResult);
    }

    // Upgrade first and third explicitly
    $final1 = new SentMessageResult(Driver::Telnyx, Direction::Sent, $to, $from, $body, [], [], MessageStatus::Sent, 'PID-FIRST');
    $final3 = new SentMessageResult(Driver::Telnyx, Direction::Sent, $to, $from, $body, [], [], MessageStatus::Sent, 'PID-THIRD');

    $repo->upgradeQueued($queued[0]->id, $final1);
    $repo->upgradeQueued($queued[2]->id, $final3);

    // Refresh all
    foreach ($queued as $m) {
        $m->refresh();
    }

    expect($queued[0]->provider_message_id)->toBe('PID-FIRST');
    expect($queued[2]->provider_message_id)->toBe('PID-THIRD');
    expect($queued[1]->provider_message_id)->toBeNull(); // middle untouched
});
