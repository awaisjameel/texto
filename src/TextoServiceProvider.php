<?php

namespace Awaisjameel\Texto;

use Awaisjameel\Texto\Commands\TextoCommand;
use Awaisjameel\Texto\Commands\TextoInstallCommand;
use Awaisjameel\Texto\Commands\TextoTestSendCommand;
use Awaisjameel\Texto\Contracts\DriverManagerInterface;
use Awaisjameel\Texto\Contracts\MessageRepositoryInterface;
use Awaisjameel\Texto\Contracts\MessageSenderInterface;
use Awaisjameel\Texto\Repositories\EloquentMessageRepository;
use Illuminate\Console\Scheduling\Schedule;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class TextoServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('texto')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_texto_messages_table')
            ->hasRoute('web')
            ->hasCommand(TextoCommand::class)
            ->hasCommand(TextoInstallCommand::class)
            ->hasCommand(TextoTestSendCommand::class);
    }

    public function packageRegistered(): void
    {
        // Bind the driver manager singleton
        $this->app->singleton(DriverManagerInterface::class, function ($app) {
            return new DriverManager(config('texto'));
        });

        // Message repository binding
        $this->app->singleton(MessageRepositoryInterface::class, function ($app) {
            return new EloquentMessageRepository;
        });

        // Sender resolves from active driver
        $this->app->bind(MessageSenderInterface::class, function ($app) {
            /** @var DriverManagerInterface $manager */
            $manager = $app->make(DriverManagerInterface::class);

            return $manager->sender();
        });

        // Facade root
        $this->app->singleton(Texto::class, function ($app) {
            return new Texto(
                $app->make(DriverManagerInterface::class),
                $app->make(MessageRepositoryInterface::class)
            );
        });
    }

    public function packageBooted(): void
    {
        // Auto-schedule the status polling job so users do NOT need to add it manually to Console\Kernel.
        // Controlled via config('texto.status_polling.enabled'). Disable there or via ENV if not desired.
        if (config('texto.status_polling.enabled')) {
            $this->app->booted(function () {
                try {
                    $schedule = $this->app->make(Schedule::class);
                    // Using class reference lets Laravel construct the job cleanly and apply queue options.
                    $schedule->job(\Awaisjameel\Texto\Jobs\StatusPollJob::class)
                        ->everyMinute()
                        ->name('texto-status-poll');
                } catch (\Throwable $e) {
                    // Silently ignore if scheduler not available (e.g., during tests without scheduling)
                }
            });
        }
    }
}
