<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Contracts;

use Awaisjameel\Texto\Enums\Driver;

interface DriverManagerInterface
{
    public function sender(?Driver $driver = null): MessageSenderInterface;

    /** @param callable():MessageSenderInterface $factory */
    public function extend(string $name, callable $factory): void;
}
