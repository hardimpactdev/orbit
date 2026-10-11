<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/**
 * The lifecycle of one task VM row. Progress inside Provisioning is derived from facts
 * (the VM exists, `node_id` is set, the Node is active), never from marker timestamps.
 * Warm is an enrolled VM of a host's pool: it has no group, no Pi token, and no model key until
 * a claim takes it, which sets the group and moves it back to Provisioning for the group's steps.
 * Parked is a Ready VM that Orbit stopped, or is starting again; only Ready allows work.
 * Failed is terminal for the row; only destroying the VM moves it on.
 */
enum TaskVmState: string
{
    case Provisioning = 'provisioning';
    case Warm = 'warm';
    case Ready = 'ready';
    case Parked = 'parked';
    case Destroying = 'destroying';
    case Destroyed = 'destroyed';
    case Failed = 'failed';
}
