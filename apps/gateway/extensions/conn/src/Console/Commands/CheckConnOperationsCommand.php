<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Console\Commands;

use Illuminate\Console\Command;
use Orbit\Extensions\Conn\Domain\ConnOperationHealth;

/** A missing worker must not silently leave device revocation pending. */
final class CheckConnOperationsCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:conn-operations-check';

    #[\Override]
    protected $description = 'Report Conn Operations unclaimed for five minutes.';

    public function handle(ConnOperationHealth $health): int
    {
        $status = self::SUCCESS;
        foreach ($health->unclaimed()->lazyById(100) as $operation) {
            $this->components->error('conn.executor_unclaimed: Conn Operation '.$operation->id.' has not been claimed for five minutes. Check the placed integration worker.');
            $status = self::FAILURE;
        }

        return $status;
    }
}
