<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Commands;

use Illuminate\Console\Command;

class TextoInstallCommand extends Command
{
    protected $signature = 'texto:install';

    protected $description = 'Publish Texto config and migrations';

    public function handle(): int
    {
        $this->call('vendor:publish', [
            '--tag' => 'texto-config',
            '--force' => true,
        ]);
        $this->call('vendor:publish', [
            '--tag' => 'texto-migrations',
            '--force' => true,
        ]);
        $this->info('Texto assets published.');

        $this->call('migrate', [
            '--force' => true,
        ]);
        $this->info('Texto migration completed.');

        return self::SUCCESS;
    }
}
