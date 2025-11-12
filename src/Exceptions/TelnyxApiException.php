<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Exceptions;

use Exception;

class TelnyxApiException extends Exception
{
    public function __construct(string $message, public int $status = 0, public ?string $telnyxCode = null, public array $context = [])
    {
        parent::__construct($message, $status);
    }
}
