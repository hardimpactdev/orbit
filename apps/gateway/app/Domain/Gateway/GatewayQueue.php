<?php

declare(strict_types=1);

namespace App\Domain\Gateway;

/**
 * The queues of the Gateway queue connection. Horizon separates short jobs from builds. See docs/reference/gateway-queue.md.
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

    /** Required base image jobs. */
    case Builds = 'builds';

    /** Jobs that record a Swamp run in its Operation, one at a time (docs/reference/operations.md). */
    case Operations = 'operations';

    /** The projection of one Operation whose native run ended, at once and beside a running pass (docs/reference/operations.md). */
    case OperationsPrompt = 'operations-prompt';
}
