<?php

declare(strict_types=1);

namespace Awaisjameel\Texto;

use Awaisjameel\Texto\Contracts\DriverManagerInterface;
use Awaisjameel\Texto\Contracts\MessageSenderInterface;
use Awaisjameel\Texto\Drivers\TelnyxSender;
use Awaisjameel\Texto\Drivers\TwilioSender;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Exceptions\TextoException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

class DriverManager implements DriverManagerInterface
{
    /** @var array<string, callable():MessageSenderInterface> */
    protected array $extensions = [];

    public function __construct(protected ConfigRepository $config) {}

    public function sender(?Driver $driver = null): MessageSenderInterface
    {
        $driver = $driver ?? Driver::from((string) $this->config->get('texto.driver', 'twilio'));
        $name = $driver->value;

        if (isset($this->extensions[$name])) {
            return ($this->extensions[$name])();
        }

        $driverConfig = $this->config->get("texto.{$name}", []);

        return match ($driver) {
            Driver::Twilio => new TwilioSender($driverConfig),
            Driver::Telnyx => new TelnyxSender($driverConfig),
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
