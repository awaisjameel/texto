<?php

namespace Awaisjameel\Texto\Tests;

use Awaisjameel\Texto\TextoServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Schema\Blueprint;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Awaisjameel\\Texto\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        // Ensure messages table exists for tests (simulate migration)
        if (! \Illuminate\Support\Facades\Schema::hasTable('texto_messages')) {
            \Illuminate\Support\Facades\Schema::create('texto_messages', function (Blueprint $table) {
                $table->id();
                $table->string('direction');
                $table->string('driver');
                $table->string('from_number')->nullable();
                $table->string('to_number')->nullable();
                $table->text('body')->nullable();
                $table->text('media_urls')->nullable();
                $table->string('status')->nullable();
                $table->string('provider_message_id')->nullable();
                $table->string('error_code')->nullable();
                $table->text('metadata')->nullable();
                $table->integer('segments_count')->nullable();
                $table->decimal('cost_estimate', 10, 4)->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('received_at')->nullable();
                $table->timestamp('status_updated_at')->nullable();
                $table->timestamps();
            });
        }
    }

    protected function getPackageProviders($app)
    {
        return [
            TextoServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
