<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Events;

use Awaisjameel\Texto\ValueObjects\SentMessageResult;

class MessageFailed
{
    public function __construct(public readonly SentMessageResult $result, public readonly ?string $reason = null) {}
}
