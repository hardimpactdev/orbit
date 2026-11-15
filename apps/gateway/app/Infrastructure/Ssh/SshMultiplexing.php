<?php

declare(strict_types=1);

namespace App\Infrastructure\Ssh;

/** How one SSH command uses the Node's shared connection. */
enum SshMultiplexing
{
    /** Ride the master, or start it when none runs. */
    case Master;

    /** Ride the master when one runs, or connect alone; never start one. */
    case Ride;

    /** Connect alone. */
    case Alone;
}
