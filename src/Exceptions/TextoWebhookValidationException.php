<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TextoWebhookValidationException extends TextoException
{
    /**
     * HTTP status returned to the provider. A 4xx tells Twilio/Telnyx the request is
     * permanently unacceptable (bad signature / malformed payload) so they stop retrying,
     * instead of an unhandled 500 that triggers retry storms.
     */
    public int $statusCode = 403;

    /**
     * Rendered automatically by Laravel's exception handler when this is thrown from a route.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => 'webhook_validation_failed',
            'message' => $this->getMessage(),
        ], $this->statusCode);
    }
}
