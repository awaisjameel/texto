<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Exceptions;

use Exception;

class TelnyxApiException extends Exception
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(string $message, public int $status = 0, public ?string $telnyxCode = null, public array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
    }
}
