<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/** What `resources/task-vms/topology.sh status RUN` says about one preparation unit. */
enum TaskVmTopologyRunState: string
{
    /** The unit runs. */
    case Running = 'running';

    /** The unit finished and the topology runs, in this boot of the VM. */
    case Ready = 'ready';

    /** The unit finished without the topology. */
    case Failed = 'failed';

    /** No unit runs and it left no result. */
    case Lost = 'lost';

    /** The unit finished in an earlier boot of the VM, so the nested guests stopped. */
    case Stale = 'stale';
}
