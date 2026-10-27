<?php

declare(strict_types=1);

namespace Awaisjameel\Texto;

use Awaisjameel\Texto\Commands\TextoInstallCommand;
use Awaisjameel\Texto\Commands\TextoTestSendCommand;
use Awaisjameel\Texto\Contracts\DriverManagerInterface;
use Awaisjameel\Texto\Contracts\MessageRepositoryInterface;
use Awaisjameel\Texto\Contracts\MessageSenderInterface;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Jobs\StatusPollJob;
use Awaisjameel\Texto\Repositories\EloquentMessageRepository;
use Awaisjameel\Texto\Support\Configuration;
use Awaisjameel\Texto\Support\ProviderHttp;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class TextoServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('texto')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigrations('create_texto_messages_table', 'upgrade_texto_message_credentials')
            ->hasRoute('web')
            ->hasCommand(TextoInstallCommand::class)
            ->hasCommand(TextoTestSendCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(DriverManagerInterface::class, DriverManager::class);

        $this->app->singleton(MessageRepositoryInterface::class, function ($app) {
            return new EloquentMessageRepository;
        });

        $this->app->bind(MessageSenderInterface::class, function ($app) {
            /** @var DriverManagerInterface $manager */
            $manager = $app->make(DriverManagerInterface::class);

            return $manager->sender();
        });

        $this->app->bind(Texto::class, function ($app) {
            return new Texto(
                $app->make(DriverManagerInterface::class),
                $app->make(MessageRepositoryInterface::class)
            );
        });
    }

    public function packageBooted(): void
    {

        if (! Http::hasMacro('twilio')) {
            Http::macro('twilio', fn (string $api = 'messaging') => ProviderHttp::twilio($api, Configuration::forDriver(Driver::Twilio)));
        }
        if (! Http::hasMacro('telnyx')) {
            Http::macro('telnyx', fn () => ProviderHttp::telnyx(Configuration::forDriver(Driver::Telnyx)));
        }
        if (! Http::hasMacro('whatsapp')) {
            Http::macro('whatsapp', fn () => ProviderHttp::whatsapp(Configuration::forDriver(Driver::Whatsapp)));
        }
        // Auto-schedule the status polling job so users do NOT need to add it manually to Console\Kernel.
        if (config('texto.status_polling.enabled')) {
            $this->app->booted(function () {
                $schedule = $this->app->make(Schedule::class);
                $schedule->job(StatusPollJob::class)
                    ->everyMinute()
                    ->name('texto-status-poll')
                    ->withoutOverlapping();
            });
        }
    }
}
