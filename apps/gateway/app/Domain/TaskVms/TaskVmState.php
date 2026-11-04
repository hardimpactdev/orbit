<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/**
 * The lifecycle of one task VM row. Progress inside Provisioning is derived from facts
 * (the VM exists, `node_id` is set, the Node is active), never from marker timestamps.
 * Parked is a Ready VM that Orbit stopped, or is starting again; only Ready allows work.
 * Failed is terminal for the row; only destroying the VM moves it on.
 * Free is a slot that is enrolled and holds no machine: its Node, hub peer and identity volume exist, and a task may
 * claim it. A slot passes through Provisioning while it is enrolled and while a task's VM starts.
 */
enum TaskVmState: string
{
    case Provisioning = 'provisioning';
    case Free = 'free';
    case Ready = 'ready';
    case Parked = 'parked';
    case Destroying = 'destroying';
    case Destroyed = 'destroyed';
    case Failed = 'failed';
}
