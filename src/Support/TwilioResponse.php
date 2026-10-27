<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Support;

use Awaisjameel\Texto\Exceptions\TwilioApiAuthException;
use Awaisjameel\Texto\Exceptions\TwilioApiException;
use Awaisjameel\Texto\Exceptions\TwilioApiNotFoundException;
use Awaisjameel\Texto\Exceptions\TwilioApiRateLimitException;
use Awaisjameel\Texto\Exceptions\TwilioApiValidationException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use JsonException;
use UnexpectedValueException;

final class TwilioResponse
{
    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function read(Response $response, string $action, array $context = [], ?string $requiredField = null): array
    {
        try {
            $body = JsonResponse::object($response);
            if ($response->successful() && $requiredField !== null) {
                JsonResponse::requiredText($body, $requiredField);
            }
        } catch (JsonException|UnexpectedValueException $exception) {
            if ($response->successful()) {
                throw new TwilioApiException('Twilio returned an invalid JSON object.', $response->status(), context: $context + ['action' => $action], previous: $exception);
            }
            $body = [];
        }
        if ($response->successful()) {
            return $body;
        }
        $status = $response->status();
        $rawCode = $body['code'] ?? null;
        $code = is_string($rawCode) || is_int($rawCode) ? (string) $rawCode : null;
        $message = $body['message'] ?? $body['detail'] ?? null;
        $message = is_string($message) ? $message : 'Twilio API error';
        $context += ['status' => $status, 'twilio_code' => $code, 'action' => $action, 'body' => $body];
        Log::warning('Twilio API request failed.', ['status' => $status, 'action' => $action, 'twilio_code' => $code]);
        throw match ($status) {
            401, 403 => new TwilioApiAuthException($message, $status, $code, $context),
            404 => new TwilioApiNotFoundException($message, $status, $code, $context),
            429 => new TwilioApiRateLimitException($message, $status, $code, $context),
            400, 422 => new TwilioApiValidationException($message, $status, $code, $context),
            default => new TwilioApiException($message, $status, $code, $context),
        };
    }
}
