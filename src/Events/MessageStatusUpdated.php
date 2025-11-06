<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Events;

use Awaisjameel\Texto\ValueObjects\WebhookProcessingResult;

class MessageStatusUpdated
{
    public function __construct(public readonly WebhookProcessingResult $result) {}
}
