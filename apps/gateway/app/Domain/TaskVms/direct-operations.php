<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmEnrollmentProjection;
use App\Domain\TaskVms\TaskVmImageProjection;
use App\Domain\TaskVms\TaskVmIncusProjection;
use App\Domain\TaskVms\TaskVmLifecycleIntentProjection;
use App\Domain\TaskVms\TaskVmPackageCacheProjection;
use App\Domain\TaskVms\TaskVmSweepProjection;
use App\Domain\TaskVms\TaskVmUpCloudImageProjection;

return ['task_vm.destroy' => [
    'workflow' => 'operation-task-vm-destroy',
    'projection' => \App\Domain\TaskVms\TaskVmDestroyProjection::class,
    'private_material' => ['upcloud_token' => 'upcloud.api', 'model_key' => 'task_vm.model_key', 'model_router' => 'task_vm.model_router'],
], 'task_vm.enroll' => [
    'workflow' => 'operation-task-vm-enroll',
    'projection' => TaskVmEnrollmentProjection::class,
    'private_material' => ['installation' => 'task_vm.installation', 'enrollment_network' => 'task_vm.enrollment_network', 'first_visit' => 'task_vm.first_visit', 'model_key' => 'task_vm.model_key', 'pi_token' => 'task_vm.pi_token', 'model_router' => 'task_vm.model_router', 'pi_artifact_file' => 'task_vm.image_artifact', 'secret_hash' => 'node.agent_secret_hash', 'worker_credentials' => 'node.worker_credentials', 'provider_bootstrap' => 'task_vm.provider_bootstrap'],
    'secret_outputs' => ['secret_hash' => 'node.agent_secret_hash', 'worker_credentials' => 'node.worker_credentials', 'provider_bootstrap' => 'task_vm.provider_bootstrap'],
], 'task_vm.prepare_runtime' => [
    'workflow' => 'operation-task-vm-prepare-runtime',
    'projection' => TaskVmEnrollmentProjection::class,
    'private_material' => ['installation' => 'task_vm.installation', 'enrollment_network' => 'task_vm.enrollment_network', 'first_visit' => 'task_vm.first_visit', 'model_key' => 'task_vm.model_key', 'pi_token' => 'task_vm.pi_token', 'model_router' => 'task_vm.model_router', 'pi_artifact_file' => 'task_vm.image_artifact', 'secret_hash' => 'node.agent_secret_hash', 'worker_credentials' => 'node.worker_credentials', 'provider_bootstrap' => 'task_vm.provider_bootstrap'],
    'secret_outputs' => ['secret_hash' => 'node.agent_secret_hash', 'worker_credentials' => 'node.worker_credentials', 'provider_bootstrap' => 'task_vm.provider_bootstrap'],
]] + array_fill_keys(TaskVmIncusProjection::Kinds, [
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
], 'task_vm.upcloud_image_build' => [
    'workflow' => 'operation-task-vm-upcloud-image-build',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'task_vm_upcloud_image_writer',
    'projection' => TaskVmUpCloudImageProjection::class,
    'private_material' => ['upcloud_token' => 'upcloud.api', 'bootstrap' => 'task_vm.image_bootstrap', 'pi_artifact_file' => 'task_vm.image_artifact'],
    'secret_outputs' => ['bootstrap' => 'task_vm.image_bootstrap'],
]];
