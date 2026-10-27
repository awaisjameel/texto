<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Support;

use Illuminate\Http\Client\Response;
use UnexpectedValueException;

final class JsonResponse
{
    /** @return array<string, mixed> */
    public static function object(Response $response): array
    {
        return self::decode($response->body());
    }

    /** @return array<string, mixed> */
    public static function decode(string $body): array
    {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (! str_starts_with(ltrim($body), '{') || ! is_array($decoded)) {
            throw new UnexpectedValueException('Expected a JSON object response.');
        }

        return self::map($decoded);
    }

    /** @return array<string, mixed> */
    public static function map(mixed $decoded): array
    {
        if (! is_array($decoded)) {
            throw new UnexpectedValueException('Expected a JSON response object.');
        }
        foreach ($decoded as $key => $value) {
            if (! is_string($key)) {
                throw new UnexpectedValueException('Expected named JSON response fields.');
            }
        }

        return $decoded;
    }

    /** @param array<string, mixed> $data */
    public static function requiredText(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new UnexpectedValueException("Provider response is missing {$key}.");
        }

        return $value;
    }
}
