<?php

namespace Awaisjameel\Texto\Tests;

use Awaisjameel\Texto\TextoServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        // Exercise the shipped migration instead of maintaining a second schema in tests.
        $migration = require dirname(__DIR__).'/database/migrations/create_texto_messages_table.php.stub';
        $migration->up();
        $credentials = require dirname(__DIR__).'/database/migrations/upgrade_texto_message_credentials.php.stub';
        $credentials->up();
    }

    protected function getPackageProviders($app)
    {
        return [
            TextoServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('t', 32)));
        config()->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
