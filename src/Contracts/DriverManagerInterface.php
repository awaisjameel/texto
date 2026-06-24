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
     * Override a built-in driver's sender with a custom implementation.
     *
     * @param  string  $name  Driver name; must match a recognized Driver enum value (e.g. "twilio", "telnyx")
     * @param  callable():MessageSenderInterface  $factory  Factory function returning sender instance
     *
     * @throws \Awaisjameel\Texto\Exceptions\TextoException If $name is not a recognized driver or already registered
     */
    public function extend(string $name, callable $factory): void;
}
