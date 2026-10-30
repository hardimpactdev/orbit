<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmIncusProjection;

return array_fill_keys([...TaskVmIncusProjection::Kinds, ...array_keys(TaskVmIncusProjection::ImageFields)], [
    'workflow' => 'operation-task-vm-incus',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'task_vm_incus_writer',
    'projection' => TaskVmIncusProjection::class,
]);
