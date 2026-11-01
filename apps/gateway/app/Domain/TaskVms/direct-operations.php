<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmDestroyProjection;
use App\Domain\TaskVms\TaskVmHubProjection;
use App\Domain\TaskVms\TaskVmImageProjection;
use App\Domain\TaskVms\TaskVmPackageCacheProjection;
use App\Domain\TaskVms\TaskVmProvisionProjection;
use App\Domain\TaskVms\TaskVmResumeProjection;
use App\Domain\TaskVms\TaskVmSweepProjection;
use App\Domain\TaskVms\TaskVmUpCloudImageProjection;

return ['task_vm.hub_converge' => [
    'workflow' => 'operation-task-vm-hub-converge',
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'hub', 'step' => 'result'],
    'projection' => TaskVmHubProjection::class,
], 'task_vm.resume' => [
    'workflow' => 'operation-task-vm-resume',
    'projection' => TaskVmResumeProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'resume', 'step' => 'result'],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-vm-upcloud-token$']],
], 'task_vm.destroy' => [
    'workflow' => 'operation-task-vm-destroy',
    'projection' => TaskVmDestroyProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'destroy', 'step' => 'result'],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^(task-vm-model-key-[1-9][0-9]*|model-router-(management|anchor)-node-[1-9][0-9]*)$']],
], 'task_vm.provision' => [
    'workflow' => 'operation-task-vm-provision',
    'projection' => TaskVmProvisionProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'provision', 'step' => 'result'],
    'private_material' => [],
    'secret_outputs' => [],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^(task-vm-(model-key|pi-token)-[1-9][0-9]*|model-router-(management|anchor)-node-[1-9][0-9]*|task-vm-upcloud-token)$']],
], 'task_vm.fill_package_cache' => [
    'workflow' => 'operation-task-vm-cache-fill',
    'projection' => TaskVmPackageCacheProjection::class,
    'private_material' => ['github_token' => 'task_vm.package_cache_github'],
], 'task_vm.image_build' => [
    'workflow' => 'operation-task-vm-image-build',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'task_vm_image_writer',
    'projection' => TaskVmImageProjection::class,
    'workflow_inputs' => true,
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
