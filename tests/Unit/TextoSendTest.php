<?php

declare(strict_types=1);

use Awaisjameel\Texto\Contracts\DriverManagerInterface;
use Awaisjameel\Texto\Drivers\FakeSender;
use Awaisjameel\Texto\Jobs\SendMessageJob;
use Awaisjameel\Texto\Models\Message;
use Awaisjameel\Texto\Texto;
use Illuminate\Support\Facades\Bus;

it('stores a sent message using fake driver', function () {
    // Use twilio driver but supply dummy credentials to avoid constructor exceptions if fallback happens.
    config()->set('texto.driver', 'twilio');
    config()->set('texto.twilio.account_sid', 'ACXXXX');
    config()->set('texto.twilio.auth_token', 'token');
    config()->set('texto.twilio.from_number', '+15551112222');
    /** @var DriverManagerInterface $manager */
    $manager = app(DriverManagerInterface::class);
    $manager->extend('twilio', fn () => new FakeSender);

    /** @var Texto $texto */
    $texto = app(Texto::class);
    $result = $texto->send('+12345678901', 'Hello world');

    expect($result->providerMessageId)->not()->toBeNull();
    expect(Message::query()->count())->toBe(1);
    $record = Message::first();
    expect($record->body)->toBe('Hello world');
    expect($record->direction)->toBe('sent');
    // reset overrides
    config()->set('texto.twilio.account_sid', null);
    config()->set('texto.twilio.auth_token', null);
    config()->set('texto.twilio.from_number', null);
});

it('captures driver config snapshot when queueing sends', function () {
    Bus::fake();
    config()->set('texto.queue', true);
    config()->set('texto.store_messages', false);
    config()->set('texto.driver', 'telnyx');
    config()->set('texto.telnyx.api_key', 'key-123');
    config()->set('texto.telnyx.messaging_profile_id', 'profile-abc');
    config()->set('texto.telnyx.from_number', '+15556667777');

    /** @var DriverManagerInterface $manager */
    $manager = app(DriverManagerInterface::class);
    $manager->extend('telnyx', fn () => new FakeSender);

    /** @var Texto $texto */
    $texto = app(Texto::class);
    $texto->send('+12345678901', 'Queued message');

    Bus::assertDispatched(SendMessageJob::class, function (SendMessageJob $job) {
        return ($job->options['driver'] ?? null) === 'telnyx'
            && ($job->options['driver_config']['api_key'] ?? null) === 'key-123';
    });

    config()->set('texto.queue', false);
    config()->set('texto.store_messages', true);
});

it('applies driver config overrides during send lifecycle', function () {
    config()->set('texto.queue', false);
    config()->set('texto.store_messages', false);
    config()->set('texto.driver', 'telnyx');
    config()->set('texto.telnyx.api_key', 'original-key');

    /** @var DriverManagerInterface $manager */
    $manager = app(DriverManagerInterface::class);
    $manager->extend('telnyx', function () {
        expect(config('texto.telnyx.api_key'))->toBe('override-key');

        return new FakeSender;
    });

    /** @var Texto $texto */
    $texto = app(Texto::class);
    $texto->send('+12345678901', 'Override config send', [
        'driver' => 'telnyx',
        'driver_config' => [
            'api_key' => 'override-key',
        ],
    ]);

    expect(config('texto.telnyx.api_key'))->toBe('original-key');

    config()->set('texto.driver', 'twilio');
    config()->set('texto.telnyx.api_key', null);
});
