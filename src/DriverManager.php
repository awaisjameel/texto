<?php

declare(strict_types=1);

namespace Awaisjameel\Texto;

use Awaisjameel\Texto\Contracts\DriverManagerInterface;
use Awaisjameel\Texto\Contracts\MessageSenderInterface;
use Awaisjameel\Texto\Drivers\EmailSender;
use Awaisjameel\Texto\Drivers\TelnyxSender;
use Awaisjameel\Texto\Drivers\TwilioSender;
use Awaisjameel\Texto\Drivers\WhatsappSender;
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
            Driver::Whatsapp => new WhatsappSender($driverConfig),
            Driver::Email => new EmailSender($driverConfig),
        };
    }

    public function extend(string $name, callable $factory): void
    {
        $name = strtolower($name);
        // sender() resolves drivers by their Driver enum value, so an extension keyed to anything
        // outside the enum could never be reached. Reject it up front instead of registering a
        // silent no-op. Extensions therefore override a built-in driver's sender (the supported
        // extension point); adding a genuinely new driver requires a new Driver enum case.
        if (Driver::tryFrom($name) === null) {
            $supported = implode(', ', array_map(fn (Driver $d) => $d->value, Driver::cases()));
            throw new TextoException("Cannot extend unknown driver '$name'. Supported drivers: $supported.");
        }
        if (isset($this->extensions[$name])) {
            throw new TextoException("Driver '$name' already registered.");
        }
        $this->extensions[$name] = $factory;
    }
}
