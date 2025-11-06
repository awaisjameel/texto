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
     * @return T
     */
    public static function exponential(Closure $callback, int $maxAttempts, int $backoffStartMs): mixed
    {
        $attempt = 0;
        $delay = $backoffStartMs;
        while (true) {
            try {
                return $callback();
            } catch (Throwable $e) {
                $attempt++;
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }
                usleep($delay * 1000);
                $delay *= 2;
            }
        }
    }
}
