<?php

declare(strict_types=1);

namespace Awaisjameel\Texto;

use Awaisjameel\Texto\Contracts\DriverManagerInterface;
use Awaisjameel\Texto\Contracts\MessageSenderInterface;
use Awaisjameel\Texto\Drivers\TelnyxSender;
use Awaisjameel\Texto\Drivers\TwilioSender;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Exceptions\TextoException;

class DriverManager implements DriverManagerInterface
{
    /** @var array<string, callable():MessageSenderInterface> */
    protected array $extensions = [];

    public function __construct(protected array $config) {}

    public function sender(?Driver $driver = null): MessageSenderInterface
    {
        $driver = $driver ?? Driver::from($this->config['driver'] ?? 'twilio');
        $name = $driver->value;

        if (isset($this->extensions[$name])) {
            return ($this->extensions[$name])();
        }

        return match ($driver) {
            Driver::Twilio => new TwilioSender($this->config['twilio'] ?? []),
            Driver::Telnyx => new TelnyxSender($this->config['telnyx'] ?? []),
            default => throw new \InvalidArgumentException("Unsupported driver: {$name}"),
        };
    }

    public function extend(string $name, callable $factory): void
    {
        $name = strtolower($name);
        if (isset($this->extensions[$name])) {
            throw new TextoException("Driver '$name' already registered.");
        }
        $this->extensions[$name] = $factory;
    }
}
