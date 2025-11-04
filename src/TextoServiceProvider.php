<?php

namespace Awaisjameel\Texto;

use Awaisjameel\Texto\Commands\TextoCommand;
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
            ->hasMigration('create_texto_table')
            ->hasCommand(TextoCommand::class);
    }
}
