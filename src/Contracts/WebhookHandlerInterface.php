<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Contracts;

use Awaisjameel\Texto\ValueObjects\WebhookProcessingResult;
use Illuminate\Http\Request;

interface WebhookHandlerInterface
{
    /**
     * Process a webhook request and return the processing result, or null when the event
     * is authentic but intentionally ignored (e.g. the echo of an outbound message this
     * package itself just sent).
     *
     * @param  Request  $request  The incoming webhook request
     */
    public function handle(Request $request): ?WebhookProcessingResult;
}
