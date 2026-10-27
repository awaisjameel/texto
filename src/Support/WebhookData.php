<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Support;

use Awaisjameel\Texto\Exceptions\TextoWebhookValidationException;
use Illuminate\Http\Request;
use JsonException;
use UnexpectedValueException;

final class WebhookData
{
    /** @return array<string, mixed> */
    public static function body(Request $request): array
    {
        try {
            return JsonResponse::decode($request->getContent());
        } catch (JsonException|UnexpectedValueException) {
            throw new TextoWebhookValidationException('Webhook body must be a valid JSON object.');
        }
    }

    /** @return array<string, mixed> */
    public static function object(mixed $value, string $field): array
    {
        try {
            return JsonResponse::map($value);
        } catch (UnexpectedValueException) {
            throw new TextoWebhookValidationException("Webhook {$field} must be an object.");
        }
    }

    /** @return list<mixed> */
    public static function items(mixed $value, string $field): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new TextoWebhookValidationException("Webhook {$field} must be a list.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return ($required is true ? non-empty-string : string|null)
     */
    public static function text(array $data, string $field, bool $required = false): ?string
    {
        $value = $data[$field] ?? null;
        if ($value === null && ! $required) {
            return null;
        }
        if (! is_string($value) || ($required && $value === '')) {
            throw new TextoWebhookValidationException("Webhook {$field} must be a ".($required ? 'non-empty ' : '').'string.');
        }

        return $value;
    }
}
