<?php

declare(strict_types=1);

namespace App\Domain\Gateway;

/**
 * The queues of the Gateway queue connection, which Laravel Horizon works with one supervisor each, so a long build
 * never holds up a short job. See docs/reference/gateway-queue.md.
 */
enum GatewayQueue: string
{
    /** The Redis queue connection of every Gateway job. */
    public const string Connection = 'gateway';

    /** Short jobs that may run side by side: the scheduled commands and the publish of an approved commit. */
    case Tasks = 'tasks';

    /** Task VM provision to destroy, the pool refill, and the host sweep, one at a time and in order. */
    case TaskVms = 'task-vms';

    /** Base image jobs, UpCloud image builds, and trusted package cache fills. */
    case Builds = 'builds';
}
