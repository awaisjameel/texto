<?php

declare(strict_types=1);

use Awaisjameel\Texto\Commands\TextoInstallCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

it('stops installation on publishing or migration failure and preserves existing config by default', function (array $codes, int $expectedCalls): void {
    $command = new class($codes) extends TextoInstallCommand
    {
        public array $calls = [];

        public function __construct(private array $codes)
        {
            parent::__construct();
        }

        public function call($command, array $arguments = []): int
        {
            $this->calls[] = [$command, $arguments];

            return array_shift($this->codes);
        }
    };
    $command->setLaravel(app());
    expect($command->run(new ArrayInput([]), new BufferedOutput))->toBe(1)
        ->and($command->calls)->toHaveCount($expectedCalls)
        ->and($command->calls[0][1]['--force'])->toBeFalse();
})->with([[[1], 1], [[0, 1], 2], [[0, 0, 1], 3]]);
