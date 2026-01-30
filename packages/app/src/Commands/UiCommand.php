<?php
declare(strict_types=1);

namespace HardImpact\Orbit\Ui\Commands;

use Illuminate\Console\Command;

class UiCommand extends Command
{
    public $signature = 'orbit-ui';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
