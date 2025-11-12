<?php

declare(strict_types=1);

namespace Awaisjameel\Texto;

use Awaisjameel\Texto\Commands\TextoCommand;
use Awaisjameel\Texto\Commands\TextoInstallCommand;
use Awaisjameel\Texto\Commands\TextoTestSendCommand;
use Awaisjameel\Texto\Contracts\DriverManagerInterface;
use Awaisjameel\Texto\Contracts\MessageRepositoryInterface;
use Awaisjameel\Texto\Contracts\MessageSenderInterface;
use Awaisjameel\Texto\Repositories\EloquentMessageRepository;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
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
            // Additional Twilio-specific config separated for clarity in adapter migration
            ->hasConfigFile('twilio')
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
            return new DriverManager($app['config']);
        });
        // Twilio API adapter bindings (only when credentials present)
        $this->app->singleton(\Awaisjameel\Texto\Contracts\TwilioMessagingApiInterface::class, function () {
            return new \Awaisjameel\Texto\Support\TwilioMessagingApi(
                config('twilio.account_sid'),
                config('twilio.auth_token')
            );
        });
        $this->app->singleton(\Awaisjameel\Texto\Contracts\TwilioConversationsApiInterface::class, function () {
            return new \Awaisjameel\Texto\Support\TwilioConversationsApi(
                config('twilio.account_sid'),
                config('twilio.auth_token')
            );
        });
        $this->app->singleton(\Awaisjameel\Texto\Contracts\TwilioContentApiInterface::class, function () {
            return new \Awaisjameel\Texto\Support\TwilioContentApi(
                config('twilio.account_sid'),
                config('twilio.auth_token')
            );
        });
        // Telnyx Messaging adapter binding (only when API key present)
        $this->app->singleton(\Awaisjameel\Texto\Contracts\TelnyxMessagingApiInterface::class, function () {
            $apiKey = config('texto.telnyx.api_key');
            return new \Awaisjameel\Texto\Support\TelnyxMessagingApi($apiKey);
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

        // Facade root - inject dependencies
        $this->app->singleton(Texto::class, function ($app) {
            return new Texto(
                $app->make(DriverManagerInterface::class),
                $app->make(MessageRepositoryInterface::class)
            );
        });
    }

    public function packageBooted(): void
    {
        // Register Twilio HTTP macro for unified direct REST calls (messaging|conversations|content)
        if (! Http::hasMacro('twilio')) {
            Http::macro('twilio', function (string $api = 'messaging') {
                $sid = config('twilio.account_sid');
                $token = config('twilio.auth_token');
                $base = config("twilio.base_urls.$api");
                $timeout = (int) config('twilio.timeout', 15);
                $client = Http::withBasicAuth($sid, $token)->timeout($timeout);
                // Twilio REST APIs universally accept form-encoded params; use form for messaging + conversations.
                // Content API accepts JSON (template creation/search); keep JSON for 'content'.
                if ($api === 'content') {
                    $client = $client->acceptJson()->asJson();
                } else {
                    $client = $client->asForm();
                }
                if ($base) {
                    $client = $client->baseUrl($base);
                }

                return $client;
            });
        }
        // Telnyx macro similar style to Twilio to provide unified PendingRequest builder
        if (!Http::hasMacro('telnyx')) {
            Http::macro('telnyx', function () {
                $apiKey = config('texto.telnyx.api_key');
                $timeout = (int) config('texto.telnyx.timeout', 30);
                $client = Http::withToken($apiKey)->timeout($timeout)->acceptJson()->asJson();
                return $client->baseUrl('https://api.telnyx.com/v2/');
            });
        }
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
