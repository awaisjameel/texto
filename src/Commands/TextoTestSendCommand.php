<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Commands;

use Awaisjameel\Texto\Enums\MessageStatus;
use Awaisjameel\Texto\Texto;
use Illuminate\Console\Command;

class TextoTestSendCommand extends Command
{
    protected $signature = 'texto:test-send {to} {body="Test message"} {--driver=}';

    protected $description = 'Send a test message using configured Texto driver';

    public function handle(): int
    {
        /** @var Texto $texto */
        $texto = app(Texto::class);
        $options = [];
        if ($this->option('driver')) {
            $options['driver'] = $this->option('driver');
        }
        try {
            $result = $texto->send($this->argument('to'), $this->argument('body'), $options);
        } catch (\Throwable $exception) {
            report($exception);
            $this->error('Message could not be sent. Check the application log.');

            return self::FAILURE;
        }
        if ($result->status === MessageStatus::Failed) {
            $this->error('Message send failed via '.$result->driver->value);

            return self::FAILURE;
        }
        $this->info('Message '.$result->status->value.' via '.$result->driver->value.' id='.$result->providerMessageId);

        return self::SUCCESS;
    }
}
