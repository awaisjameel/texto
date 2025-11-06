<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Events;

use Awaisjameel\Texto\ValueObjects\SentMessageResult;

class MessageSent
{
    public function __construct(public readonly SentMessageResult $result) {}
}
