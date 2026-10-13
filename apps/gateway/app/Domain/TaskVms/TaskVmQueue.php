<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/**
 * The queues of task VM jobs, on the `task-vms` database connection. Each queue has its own worker, a systemd unit on
 * the Gateway host that runs one job at a time, so a long build on one queue holds up no job on another.
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

    /** Push a task VM's approved commit from the Gateway's mirror, at most about 27 minutes for a VM that sends slowly. */
    case Git = 'task-vm-git';

    /** The worker's `--timeout`, for a job that sets none, below the queue's `retry_after` of 1800 seconds. */
    public function workerTimeout(): int
    {
        return $this === self::Vms ? 1500 : 1700;
    }
}
