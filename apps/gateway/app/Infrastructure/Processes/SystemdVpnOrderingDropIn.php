<?php

declare(strict_types=1);

namespace App\Infrastructure\Processes;

/** Reserved by the agent; extension service hooks must not replace this drop-in. */
final class SystemdVpnOrderingDropIn
{
    public const string FILE_NAME = 'orbit-vpn.conf';
}
