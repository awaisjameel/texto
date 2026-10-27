<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Support;

use Awaisjameel\Texto\Enums\Driver;
use InvalidArgumentException;

/** Validated access to provider configuration, captured per sender/adapter instance. */
final class Configuration
{
    /** @param array<string, mixed> $values */
    public function __construct(private readonly array $values) {}

    /** @return array<string, mixed> */
    public static function forDriver(Driver $driver): array
    {
        $values = config('texto.'.$driver->value, []);
        if (! is_array($values)) {
            throw new InvalidArgumentException('Provider configuration must be an array.');
        }
        foreach ($values as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Provider configuration keys must be strings.');
            }
        }

        return $values;
    }

    public function text(string $key, string $default = ''): string
    {
        $value = data_get($this->values, $key, $default);
        if ($value === null) {
            return $default;
        }
        if (! is_string($value)) {
            throw new InvalidArgumentException("Provider setting {$key} must be a string.");
        }

        return $value;
    }

    public function integer(string $key, int $default, int $minimum = 0): int
    {
        $value = data_get($this->values, $key, $default);
        $parsed = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;
        if ($parsed === false || $parsed < $minimum) {
            throw new InvalidArgumentException("Provider setting {$key} must be an integer >= {$minimum}.");
        }

        return $parsed;
    }

    public function flag(string $key, bool $default): bool
    {
        $value = filter_var(data_get($this->values, $key, $default), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($value === null) {
            throw new InvalidArgumentException("Provider setting {$key} must be boolean.");
        }

        return $value;
    }
}
