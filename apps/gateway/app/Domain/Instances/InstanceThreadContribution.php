<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\Instance;

/**
 * An extension's record of the threads in a container Instance's T3 server (docs/reference/extensions.md#plug-in-points). Each method
 * changes only the extension's own records, does nothing for an Instance it holds no server of, and must not fail its caller.
 */
interface InstanceThreadContribution
{
    /** A thread that a task copy's turn started in the Instance's T3 server, once the turn's projection committed. */
    public function recordThread(Instance $instance, string $threadId, string $title): void;

    /** The last read of the Instance's threads before a sleep is admitted, outside the Instance lock. */
    public function beforeSleep(Instance $instance): void;
}
