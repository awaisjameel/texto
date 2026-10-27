<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Support;

use Closure;
use Throwable;

final class Retry
{
    /**
     * @template T
     *
     * @param  Closure():T  $callback
     * @param  null|Closure(Throwable):bool  $shouldRetry
     * @return T
     */
    public static function exponential(Closure $callback, int $maxAttempts, int $backoffStartMs, ?Closure $shouldRetry = null): mixed
    {
        $maximumDelay = intdiv(PHP_INT_MAX, 1000);
        if ($maxAttempts < 1 || $backoffStartMs < 0 || $backoffStartMs > $maximumDelay) {
            throw new \InvalidArgumentException('Retry attempts must be positive and the millisecond backoff must fit the native timer.');
        }
        $attempt = 0;
        $delay = $backoffStartMs;
        while (true) {
            try {
                return $callback();
            } catch (Throwable $e) {
                $attempt++;
                if ($attempt >= $maxAttempts || ($shouldRetry !== null && ! $shouldRetry($e))) {
                    throw $e;
                }
                usleep($delay * 1000);
                $delay = min($maximumDelay, $delay * 2);
            }
        }
    }
}
