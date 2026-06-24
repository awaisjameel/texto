<?php

namespace Awaisjameel\Texto\Facades;

use Awaisjameel\Texto\ValueObjects\SentMessageResult;
use Illuminate\Support\Facades\Facade;

/**
 * @method static SentMessageResult send(string $to, string $body, array $options = [])
 * @method static SentMessageResult sendDirect(string $to, string $body, array $options = [])
 *
 * @see \Awaisjameel\Texto\Texto
 */
class Texto extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Awaisjameel\Texto\Texto::class;
    }
}
