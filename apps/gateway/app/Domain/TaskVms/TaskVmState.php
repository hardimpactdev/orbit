<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/**
 * The lifecycle of one task VM row. Progress inside Provisioning is derived from facts
 * (the VM exists, `node_id` is set, the Node is active), never from marker timestamps.
 * Parked is a Ready VM that Orbit stopped, or is starting again; only Ready allows work.
 * A slot row (a row with a slot number) lives for one task start: Releasing deletes its VM and its Node, and a Failed one is released again.
 * Failed is terminal for any other row; only destroying the VM moves it on.
 */
enum TaskVmState: string
{
    case Provisioning = 'provisioning';
    case Ready = 'ready';
    case Parked = 'parked';
    case Releasing = 'releasing';
    case Destroying = 'destroying';
    case Destroyed = 'destroyed';
    case Failed = 'failed';
}
