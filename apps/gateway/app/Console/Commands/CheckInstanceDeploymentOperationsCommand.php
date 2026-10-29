<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Instances\Deployment\InstanceDeploymentHealth;
use Illuminate\Console\Command;

/** Reports missing deployment executors without dispatching or changing work. */
final class CheckInstanceDeploymentOperationsCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:instance-deployment-operations-check';

    #[\Override]
    protected $description = 'Report Instance deploy and rollback Operations unclaimed for five minutes.';

    public function handle(InstanceDeploymentHealth $health): int
    {
        $status = self::SUCCESS;
        foreach ($health->unclaimed()->lazyById(100) as $operation) {
            $this->components->error('deployment.executor_unclaimed: Instance Operation '.$operation->id.' has not been claimed for five minutes. Check Services placement and the target worker.');
            $status = self::FAILURE;
        }

        return $status;
    }
}
