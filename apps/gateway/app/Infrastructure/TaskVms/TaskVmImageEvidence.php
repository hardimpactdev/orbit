<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Models\Operation;
use App\Models\TaskVmImageBuild;

/** Reads the successful publication recorded for a candidate fingerprint. */
final class TaskVmImageEvidence
{
    public static function published(TaskVmImageBuild $build): ?Operation
    {
        return Operation::query()->where('kind', 'task_vm.image_publish')->where('target_type', 'task_vm_image')->where('target_id', $build->id)
            ->where('status', 'succeeded')->where('result->fingerprint', $build->image_fingerprint)->latest('id')->first();
    }
}
