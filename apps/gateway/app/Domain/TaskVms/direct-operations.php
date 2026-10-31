<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmIncusProjection;
use App\Domain\TaskVms\TaskVmLifecycleIntentProjection;
use App\Domain\TaskVms\TaskVmPackageCacheProjection;

return array_fill_keys(TaskVmIncusProjection::Kinds, [
    'workflow' => 'operation-task-vm-incus',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'task_vm_incus_writer',
    'projection' => TaskVmIncusProjection::class,
]) + ['task_vm.lifecycle_intent' => [
    'workflow' => 'operation-task-vms',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'task_vm_lifecycle_intent_writer',
    'projection' => TaskVmLifecycleIntentProjection::class,
], 'task_vm.fill_package_cache' => [
    'workflow' => 'operation-task-vm-cache-fill',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'task_vm_cache_fill_writer',
    'projection' => TaskVmPackageCacheProjection::class,
    'private_material' => ['github_token' => 'task_vm.package_cache_github'],
]];
