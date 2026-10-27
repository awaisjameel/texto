<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Commands;

use Illuminate\Console\Command;

class TextoInstallCommand extends Command
{
    protected $signature = 'texto:install {--force : Overwrite published configuration and migrations}';

    protected $description = 'Publish Texto config and migrations';

    public function handle(): int
    {
        if ($this->call('vendor:publish', [
            '--tag' => 'texto-config',
            '--force' => (bool) $this->option('force'),
        ]) !== self::SUCCESS) {
            return self::FAILURE;
        }
        if ($this->call('vendor:publish', [
            '--tag' => 'texto-migrations',
            '--force' => (bool) $this->option('force'),
        ]) !== self::SUCCESS) {
            return self::FAILURE;
        }
        $this->info('Texto assets published.');

        if ($this->call('migrate', [
            '--force' => true,
        ]) !== self::SUCCESS) {
            return self::FAILURE;
        }
        $this->info('Texto migration completed.');

        return self::SUCCESS;
    }
}
