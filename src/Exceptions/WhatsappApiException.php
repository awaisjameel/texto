<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Exceptions;

use Exception;

class WhatsappApiException extends Exception
{
    /** @param array<string, mixed> $context */
    public function __construct(
        string $message,
        public int $status = 0,
        public ?string $graphCode = null,
        public ?string $graphSubcode = null,
        public array $context = [],
    ) {
        parent::__construct($message, $status);
    }
}
