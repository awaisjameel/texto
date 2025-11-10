<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Contracts;

use Awaisjameel\Texto\Enums\Driver;

interface DriverManagerInterface
{
    /**
     * Get a message sender for the specified driver.
     *
     * @param  Driver|null  $driver  The driver to use, or null for default
     */
    public function sender(?Driver $driver = null): MessageSenderInterface;

    /**
     * Register a custom driver implementation.
     *
     * @param  string  $name  Driver name
     * @param  callable():MessageSenderInterface  $factory  Factory function returning sender instance
     */
    public function extend(string $name, callable $factory): void;
}
