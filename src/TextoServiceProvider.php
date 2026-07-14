<?php

declare(strict_types=1);

namespace Awaisjameel\Texto;

use Awaisjameel\Texto\Commands\TextoInstallCommand;
use Awaisjameel\Texto\Commands\TextoTestSendCommand;
use Awaisjameel\Texto\Contracts\DriverManagerInterface;
use Awaisjameel\Texto\Contracts\MessageRepositoryInterface;
use Awaisjameel\Texto\Contracts\MessageSenderInterface;
use Awaisjameel\Texto\Jobs\StatusPollJob;
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
            Http::macro('twilio', function (string $api = 'messaging') {
                $base = config("texto.twilio.base_urls.$api");
                $sid = config('texto.twilio.account_sid');
                $token = config('texto.twilio.auth_token');
                $timeout = (int) config('texto.twilio.timeout', 30);

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
        if (! Http::hasMacro('whatsapp')) {
            Http::macro('whatsapp', function () {
                $base = config('texto.whatsapp.base_url', 'https://graph.facebook.com/v25.0/');
                $token = config('texto.whatsapp.access_token');
                $timeout = (int) config('texto.whatsapp.timeout', 30);

                return Http::withToken($token)
                    ->acceptJson()
                    ->asJson()
                    ->timeout($timeout)
                    ->connectTimeout($timeout)
                    ->baseUrl($base);
            });
        }
        // Auto-schedule the status polling job so users do NOT need to add it manually to Console\Kernel.
        if (config('texto.status_polling.enabled')) {
            $this->app->booted(function () {
                try {
                    $schedule = $this->app->make(Schedule::class);
                    // Using class reference lets Laravel construct the job cleanly and apply queue options.
                    $schedule->job(StatusPollJob::class)
                        ->everyMinute()
                        ->name('texto-status-poll')
                        ->withoutOverlapping(); // a run longer than a minute must not double-poll
                } catch (\Throwable $e) {
                    // Silently ignore if scheduler not available (e.g., during tests without scheduling)
                }
            });
        }
    }
}
