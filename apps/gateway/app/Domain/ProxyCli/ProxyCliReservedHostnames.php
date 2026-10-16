<?php

declare(strict_types=1);

namespace App\Domain\ProxyCli;

use App\Domain\Routes\ContributedReservedHostnames;

/** Reserves the collector hostname, also while proxycli is off or not set up. The apex stays free. */
final readonly class ProxyCliReservedHostnames implements ContributedReservedHostnames
{
    public function hostnames(): array
    {
        return [ProxyCliHostname::Value];
    }
}
