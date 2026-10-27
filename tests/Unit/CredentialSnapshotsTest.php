<?php

declare(strict_types=1);

use Awaisjameel\Texto\Contracts\DriverManagerInterface;
use Awaisjameel\Texto\Contracts\MessageRepositoryInterface;
use Awaisjameel\Texto\Jobs\SendMessageJob;
use Awaisjameel\Texto\Jobs\StatusPollJob;
use Awaisjameel\Texto\Mail\TextoMail;
use Awaisjameel\Texto\Models\Message;
use Awaisjameel\Texto\Texto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

function textoDatabaseQueue(): void
{
    $source = dirname(__DIR__, 2).'/vendor/laravel/framework/src/Illuminate/Queue/Console/stubs/jobs.stub';
    $file = tempnam(sys_get_temp_dir(), 'texto-queue-');
    try {
        file_put_contents($file, str_replace('{{table}}', 'jobs', file_get_contents($source)));
        $migration = require $file;
        $migration->up();
    } finally {
        unlink($file);
    }
    config()->set('queue.connections.texto-test', ['driver' => 'database', 'connection' => 'testing', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90, 'after_commit' => false]);
    config()->set('queue.default', 'texto-test');
}

function textoTenantConfiguration(): array
{
    return ['api_key' => 'tenant-private-key', 'messaging_profile_id' => 'tenant-profile', 'from_number' => '+15550002222', 'base_url' => 'https://tenant.example/v2/'];
}

it('encrypts credential snapshots and polls with the original tenant after global configuration changes', function (): void {
    config()->set('texto.telnyx.api_key', 'global-private-key');
    Http::fake([
        'https://tenant.example/v2/messages' => Http::response(['data' => ['id' => 'tenant-message', 'to' => [['status' => 'queued']]]], 201),
        'https://tenant.example/v2/messages/tenant-message' => Http::response(['data' => ['id' => 'tenant-message', 'to' => [['status' => 'delivered']]]]),
    ]);
    $result = app(Texto::class)->sendDirect('+15551234567', 'tenant message', ['driver' => 'telnyx', 'driver_config' => textoTenantConfiguration()]);
    $record = Message::firstOrFail();
    expect($record->driver_config['api_key'])->toBe('tenant-private-key')
        ->and($record->getRawOriginal('driver_config'))->not->toContain('tenant-private-key')
        ->and($record->toArray())->not->toHaveKey('driver_config')
        ->and(json_encode($result))->not->toContain('tenant-private-key');
    config()->set('texto.telnyx.api_key', 'another-global-key');
    config()->set('texto.status_polling', ['enabled' => true, 'min_age_seconds' => 0, 'max_attempts' => 5, 'queued_max_attempts' => 2, 'backoff_seconds' => 0, 'batch_limit' => 10]);
    (new StatusPollJob)->handle(app(MessageRepositoryInterface::class), app(DriverManagerInterface::class));
    expect($record->fresh()->status)->toBe('delivered')->and(config('texto.telnyx.api_key'))->toBe('another-global-key');
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/tenant-message') && $request->hasHeader('Authorization', 'Bearer tenant-private-key'));
});

it('encrypts actual database queue payloads and upgrades one ledger row through a real queued handler', function (): void {
    textoDatabaseQueue();
    config()->set('texto.queue', true);
    Http::fake(['https://tenant.example/v2/messages' => Http::response(['data' => ['id' => 'queued-tenant-message', 'to' => [['status' => 'sent']]]], 201)]);
    app(Texto::class)->send('+15551234567', 'queued tenant message', ['driver' => 'telnyx', 'driver_config' => textoTenantConfiguration()]);
    $record = Message::firstOrFail();
    expect($record->status)->toBe('queued')->and(DB::table('jobs')->value('payload'))->not->toContain('tenant-private-key');
    $job = Queue::connection('texto-test')->pop();
    expect($job)->not->toBeNull();
    $job->fire();
    expect(Message::count())->toBe(1)->and($record->fresh()->provider_message_id)->toBe('queued-tenant-message')
        ->and($record->fresh()->status)->toBe('sent');
    (new SendMessageJob($record->id, '+15551234567', 'queued tenant message', ['driver' => 'telnyx', 'driver_config' => textoTenantConfiguration()]))->handle(app(Texto::class));
    Http::assertSentCount(1);
});

it('preserves quoted recipient and sender display names through the encrypted queue', function (): void {
    textoDatabaseQueue();
    Mail::fake();
    config()->set('texto.queue', true);
    app(Texto::class)->send('"Doe, Jane" <jane@example.com>', 'queued email', ['driver' => 'email', 'from' => '"Sender \\"Quoted\\"" <sender@example.com>']);
    Queue::connection('texto-test')->pop()->fire();
    Mail::assertSent(TextoMail::class, fn ($mail) => $mail->hasTo('jane@example.com', 'Doe, Jane') && $mail->hasFrom('sender@example.com', 'Sender "Quoted"'));
    expect(Message::count())->toBe(1)->and(Message::firstOrFail()->status)->toBe('sent');
});
