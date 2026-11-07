<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Models\TaskVm;

/** The power observation of a task VM on its selected compute provider. */
interface TaskVmProvider
{
    /** Returns null when the VM is absent. */
    public function observe(TaskVm $vm): ?VmObservation;
}
