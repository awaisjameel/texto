<?php

namespace Awaisjameel\Texto\Commands;

use Illuminate\Console\Command;

class TextoCommand extends Command
{
    public $signature = 'texto';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
