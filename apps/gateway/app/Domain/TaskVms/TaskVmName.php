<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Models\TaskVm;

/** The provider name allow-list shared by release, park and resume. */
final readonly class TaskVmName
{
    /** A slot keeps its original host name; old-pool VMs have no slot number. */
    public static function accepts(TaskVm $vm): bool
    {
        $shape = $vm->slot_number === null ? '/^tvm-[0-9]+$/' : '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?-tvm-'.$vm->slot_number.'$/';

        return preg_match($shape, $vm->name) === 1;
    }
}
