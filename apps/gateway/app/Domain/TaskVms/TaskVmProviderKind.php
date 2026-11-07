<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/** Task VMs run only on a configured Incus host. */
enum TaskVmProviderKind: string
{
    case Incus = 'incus';

    public function needsHostNode(): bool
    {
        return true;
    }
}
