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

    /** Short jobs: the scheduled commands, task VM provision to destroy, publish, sweep, and pool refill. */
    case Tasks = 'tasks';

    /** Long jobs: base image, UpCloud template, and workspace template builds. */
    case Builds = 'builds';
}
