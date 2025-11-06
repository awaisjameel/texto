<?php

declare(strict_types=1);

use Awaisjameel\Texto\Contracts\DriverManagerInterface;
use Awaisjameel\Texto\Drivers\FakeSender;
use Awaisjameel\Texto\Models\Message;
use Awaisjameel\Texto\Texto;

it('stores a sent message using fake driver', function () {
    config()->set('texto.driver', 'twilio');
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
});
