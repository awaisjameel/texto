<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Contracts;

use Awaisjameel\Texto\ValueObjects\WebhookProcessingResult;
use Illuminate\Http\Request;

interface WebhookHandlerInterface
{
    /**
     * Process a webhook request and return processing result.
     *
     * @param  Request  $request  The incoming webhook request
     */
    public function handle(Request $request): WebhookProcessingResult;
}
