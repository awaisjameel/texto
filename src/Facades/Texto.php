<?php

namespace Awaisjameel\Texto\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Awaisjameel\Texto\Texto
 */
class Texto extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Awaisjameel\Texto\Texto::class;
    }
}
