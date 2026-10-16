<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\TaskWorkspaceLeaseRecord;

interface TaskWorkspaceOperationRecovery
{
    /** True only after every dispatched operation has exact terminal evidence or a no-start tombstone. */
    public function settled(TaskWorkspaceLeaseRecord $lease): bool;
}
