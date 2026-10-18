<?php

declare(strict_types=1);

namespace App\Domain\Gateway;

/**
 * The queues of the Gateway queue connection, which Laravel Horizon works with four supervisors, so a long build
 * never holds up a short job. See docs/reference/gateway-queue.md.
 */
enum GatewayQueue: string
{
    /** The Redis queue connection of every Gateway job. */
    public const string Connection = 'gateway';

    /** Scheduled commands and approved-commit publication. */
    case Tasks = 'tasks';

    /** Bounded problem collection, below tasks in the same supervisor. */
    case Problems = 'problems';

    /** Task VM provision to destroy, the pool refill, and the host sweep, one at a time and in order. */
    case TaskVms = 'task-vms';

    /** Base image jobs, UpCloud image builds, and trusted package cache fills. */
    case Builds = 'builds';

    /** Operations, such as an Instance deploy: one job runs one operation (docs/reference/operations.md). */
    case Operations = 'operations';
}
