<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/**
 * The queues of task VM jobs, on the `task-vms` database connection. Each queue has its own worker, which runs one job
 * at a time, so a long build on one queue holds up no job on another.
 */
enum TaskVmQueue: string
{
    /** The database queue connection of every task VM job. */
    public const string Connection = 'task-vms';

    /** Provision, enroll, prepare, park, resume, and destroy task VMs; refill the pool; sweep the hosts. */
    case Vms = 'task-vms';

    /** Refresh and build workspace templates. */
    case Templates = 'task-vm-templates';

    /** The nightly base image refreshes of the Incus hosts and of UpCloud, each up to about 25 minutes. */
    case Images = 'task-vm-images';
}
