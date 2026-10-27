<?php

declare(strict_types=1);

use Awaisjameel\Texto\Jobs\StatusPollJob;
use Awaisjameel\Texto\Support\Retry;
use Awaisjameel\Texto\TextoServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

it('prevents overlapping queued poll execution in addition to scheduler dispatch', function (): void {
    $executions = 0;
    $first = (new StatusPollJob)->middleware()[0];
    $second = (new StatusPollJob)->middleware()[0];
    $first->handle(new StatusPollJob, function () use ($second, &$executions) {
        $executions++;
        $second->handle(new StatusPollJob, function () use (&$executions) {
            $executions++;
        });
    });
    expect($executions)->toBe(1);
});

it('registers the poll schedule when enabled', function (): void {
    config()->set('texto.status_polling.enabled', true);
    (new TextoServiceProvider(app()))->packageBooted();
    expect(collect(app(Schedule::class)->events())->contains(fn ($event) => $event->description === 'texto-status-poll'))->toBeTrue();
});

it('rejects invalid retry settings before invoking the provider', function (int $attempts, int $delay): void {
    expect(fn () => Retry::exponential(fn () => throw new LogicException('Provider must not run.'), $attempts, $delay))->toThrow(InvalidArgumentException::class);
})->with([[0, 0], [1, -1], [1, PHP_INT_MAX]]);
