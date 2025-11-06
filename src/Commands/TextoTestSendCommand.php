<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Commands;

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
        $result = $texto->send($this->argument('to'), $this->argument('body'), $options);
        $this->info('Message sent via '.$result->driver->value.' id='.$result->providerMessageId);

        return self::SUCCESS;
    }
}
