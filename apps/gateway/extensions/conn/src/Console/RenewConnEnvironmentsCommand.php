<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Console;

use Illuminate\Console\Command;
use Orbit\Extensions\Conn\Domain\ConnHeldSessionSweep;
use Orbit\Extensions\Conn\Domain\ConnRenewalSweep;

/** The hourly tick of the renewal of container T3 servers' Gateway sessions, then of the sessions the Gateway holds for Nodes. */
final class RenewConnEnvironmentsCommand extends Command
{
    /** @var string */
    #[\Override]
    protected $signature = 'conn:environments:renew';

    /** @var string */
    #[\Override]
    protected $description = 'Admit renewals of the Gateway sessions and held Node sessions on container T3 servers that end within seven days';

    public function handle(ConnRenewalSweep $sweep, ConnHeldSessionSweep $held): int
    {
        $admitted = $sweep->run();
        $this->info('Admitted '.count($admitted).' renewal(s).');
        $this->info('Admitted '.count($held->run()).' held session renewal(s) and revoke(s).');

        return self::SUCCESS;
    }
}
