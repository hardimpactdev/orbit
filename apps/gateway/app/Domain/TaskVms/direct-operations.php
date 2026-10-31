<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmImageProjection;
use App\Domain\TaskVms\TaskVmIncusProjection;
use App\Domain\TaskVms\TaskVmLifecycleIntentProjection;
use App\Domain\TaskVms\TaskVmPackageCacheProjection;
use App\Domain\TaskVms\TaskVmSweepProjection;

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
], 'task_vm.image_build' => [
    'workflow' => 'operation-task-vm-image-build',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'task_vm_image_writer',
    'projection' => TaskVmImageProjection::class,
    'private_material' => ['pi_artifact_file' => 'task_vm.image_artifact'],
], 'task_vm.sweep_host' => [
    'workflow' => 'operation-task-vm-sweep',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'task_vm_sweep_writer',
    'projection' => TaskVmSweepProjection::class,
    'private_material' => ['upcloud_token' => 'upcloud.api'],
]];
