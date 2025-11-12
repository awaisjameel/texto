<?php

declare(strict_types=1);

namespace Awaisjameel\Texto;

use Awaisjameel\Texto\Commands\TextoInstallCommand;
use Awaisjameel\Texto\Commands\TextoTestSendCommand;
use Awaisjameel\Texto\Contracts\DriverManagerInterface;
use Awaisjameel\Texto\Contracts\MessageRepositoryInterface;
use Awaisjameel\Texto\Contracts\MessageSenderInterface;
use Awaisjameel\Texto\Repositories\EloquentMessageRepository;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
            ->hasMigration('create_texto_messages_table')
            ->hasRoute('web')
            ->hasCommand(TextoInstallCommand::class)
            ->hasCommand(TextoTestSendCommand::class);
    }

    public function packageRegistered(): void
    {
        // Bind the driver manager as a singleton so driver extensions applied during runtime (e.g. in tests)
        $this->app->singleton(DriverManagerInterface::class, function ($app) {
            return new DriverManager($app['config']);
        });
        // Twilio API adapter bindings (only when credentials present). Skip binding to avoid test-time TypeErrors.
        $twilioSid = config('texto.twilio.account_sid');
        $twilioToken = config('texto.twilio.auth_token');
        if ($twilioSid && $twilioToken) {
            $this->app->singleton(\Awaisjameel\Texto\Contracts\TwilioMessagingApiInterface::class, function () use ($twilioSid, $twilioToken) {
                return new \Awaisjameel\Texto\Support\TwilioMessagingApi($twilioSid, $twilioToken);
            });
            $this->app->singleton(\Awaisjameel\Texto\Contracts\TwilioConversationsApiInterface::class, function () use ($twilioSid, $twilioToken) {
                return new \Awaisjameel\Texto\Support\TwilioConversationsApi($twilioSid, $twilioToken);
            });
            $this->app->singleton(\Awaisjameel\Texto\Contracts\TwilioContentApiInterface::class, function () use ($twilioSid, $twilioToken) {
                return new \Awaisjameel\Texto\Support\TwilioContentApi($twilioSid, $twilioToken);
            });
        }
        // Telnyx Messaging adapter binding (only when API key present)
        $telnyxKey = config('texto.telnyx.api_key');
        if ($telnyxKey) {
            $this->app->singleton(\Awaisjameel\Texto\Contracts\TelnyxMessagingApiInterface::class, function () use ($telnyxKey) {
                return new \Awaisjameel\Texto\Support\TelnyxMessagingApi($telnyxKey);
            });
        }

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
        $this->app->bind(Texto::class, function ($app) {
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
                $base = config("texto.twilio.base_urls.$api");
                $sid = config('texto.twilio.account_sid');
                $token = config('texto.twilio.auth_token');
                $timeout = (int) config('texto.twilio.timeout', 30);

                // Start with a plain client; add auth only when both credentials are present.
                $client = Http::timeout($timeout)
                    ->connectTimeout($timeout);
                if ($sid && $token) {
                    $client = $client->withBasicAuth($sid, $token);
                }

                // Twilio REST APIs universally accept form-encoded params for messaging + conversations.
                // Content API prefers JSON.
                if ($api === 'content') {
                    $client = $client->acceptJson()->asJson();
                } else {
                    $client = $client->asForm();
                }
                if ($base) {
                    $client = $client->baseUrl($base);
                }

                Log::info('Texto Twilio HTTP macro using base URL', ['api' => $api, 'base_url' => $base]);

                return $client;
            });
        }
        // Telnyx macro
        if (! Http::hasMacro('telnyx')) {
            Http::macro('telnyx', function () {
                $base = config('texto.telnyx.base_url', 'https://api.telnyx.com/v2/');
                $apiKey = config('texto.telnyx.api_key');
                $timeout = (int) config('texto.telnyx.timeout', 30);

                $client = Http::withToken($apiKey)
                    ->acceptJson()
                    ->asJson()
                    ->timeout($timeout)
                    ->connectTimeout($timeout);

                return $client->baseUrl($base);
            });
        }
        // Auto-schedule the status polling job so users do NOT need to add it manually to Console\Kernel.
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
