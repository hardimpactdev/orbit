<?php

declare(strict_types=1);

namespace App\Domain\Gateway;

/**
 * The queues of the Gateway queue connection. Horizon separates short jobs from builds; the build supervisor
 * checks required work before optional work. See docs/reference/gateway-queue.md.
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

    /** Required base image jobs and trusted package cache fills. */
    case Builds = 'builds';

    /** Optional UpCloud image builds, after waiting required build jobs. */
    case OptionalBuilds = 'builds-optional';

    /** Operations, such as an Instance deploy: one job runs one operation (docs/reference/operations.md). */
    case Operations = 'operations';
}
