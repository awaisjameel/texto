<?php

declare(strict_types=1);

use Awaisjameel\Texto\Contracts\DriverManagerInterface;
use Awaisjameel\Texto\Drivers\FakeSender;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Exceptions\TextoException;

it('resolves a custom sender registered for a built-in driver', function () {
    /** @var DriverManagerInterface $manager */
    $manager = app(DriverManagerInterface::class);
    $manager->extend('twilio', fn () => new FakeSender);

    expect($manager->sender(Driver::Twilio))->toBeInstanceOf(FakeSender::class);
});

it('rejects extending an unknown driver instead of silently registering an unreachable one', function () {
    /** @var DriverManagerInterface $manager */
    $manager = app(DriverManagerInterface::class);
    $manager->extend('vonage', fn () => new FakeSender);
})->throws(TextoException::class, "Cannot extend unknown driver 'vonage'");

it('rejects registering the same driver twice', function () {
    /** @var DriverManagerInterface $manager */
    $manager = app(DriverManagerInterface::class);
    $manager->extend('telnyx', fn () => new FakeSender);
    $manager->extend('telnyx', fn () => new FakeSender);
})->throws(TextoException::class, "Driver 'telnyx' already registered.");
