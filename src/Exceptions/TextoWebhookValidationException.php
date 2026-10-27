<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TextoWebhookValidationException extends TextoException
{
    /**
     * Explicit validation status for a bad signature or malformed payload.
     * Provider retry policies vary; the response does not guarantee retries stop.
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
