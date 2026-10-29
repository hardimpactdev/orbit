<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Operations\OperationHealth;
use Illuminate\Console\Command;

final class CheckOperationHealthCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:operations-check';

    #[\Override]
    protected $description = 'Report unclaimed, stalled or repeatedly reclaimed Swamp Operations.';

    public function handle(OperationHealth $health): int
    {
        $alerts = $health->check();
        foreach ($alerts as $id => $code) {
            $this->components->error($code.': Operation '.$id.' needs executor assistance.');
        }

        return $alerts === [] ? self::SUCCESS : self::FAILURE;
    }
}
