<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Exceptions;

use Exception;

class TwilioApiException extends Exception
{
    public function __construct(string $message, public int $status = 0, public ?string $twilioCode = null, public array $context = [])
    {
        parent::__construct($message, $status);
    }
}
