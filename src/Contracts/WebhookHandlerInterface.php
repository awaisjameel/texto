<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Contracts;

use Awaisjameel\Texto\ValueObjects\WebhookProcessingResult;
use Illuminate\Http\Request;

interface WebhookHandlerInterface
{
    public function handle(Request $request): WebhookProcessingResult;
}
