<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Console;

use Illuminate\Console\Command;
use Orbit\Extensions\Conn\Domain\ConnOwedWork;

/** The reconcile tick of the revokes and cleanups container T3 servers are owed ([Owed work](/reference/conn#owed-work)). */
final class AdvanceConnOwedWorkCommand extends Command
{
    /** @var string */
    #[\Override]
    protected $signature = 'conn:owed-work:advance';

    /** @var string */
    #[\Override]
    protected $description = 'Admit the owed held-session revokes and Gateway session cleanups of container T3 servers';

    public function handle(ConnOwedWork $owed): int
    {
        $this->info('Advanced the owed work of '.count($owed->reconcile()).' server(s).');

        return self::SUCCESS;
    }
}
