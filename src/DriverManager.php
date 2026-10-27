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
use Awaisjameel\Texto\Support\Configuration;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

class DriverManager implements DriverManagerInterface
{
    /** @var array<string, callable(array<string, mixed>):MessageSenderInterface> */
    protected array $extensions = [];

    public function __construct(protected ConfigRepository $config) {}

    public function sender(?Driver $driver = null, ?array $configuration = null): MessageSenderInterface
    {
        if ($driver === null) {
            $configured = $this->config->get('texto.driver', 'twilio');
            if (! is_string($configured)) {
                throw new TextoException('The default message driver must be a string.');
            }
            $driver = Driver::from($configured);
        }
        $name = $driver->value;
        $driverConfig = $configuration ?? Configuration::forDriver($driver);

        if (isset($this->extensions[$name])) {
            return ($this->extensions[$name])($driverConfig);
        }

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
