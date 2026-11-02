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
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^(task-vm-model-key-[1-9][0-9]*|model-router-(management|anchor)-node-[1-9][0-9]*|task-vm-upcloud-token)$']],
], 'task_vm.provision' => [
    'workflow' => 'operation-task-vm-provision',
    'projection' => TaskVmProvisionProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'provision', 'step' => 'result'],
    'private_material' => [],
    'secret_outputs' => [],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^(task-vm-(model-key|pi-token|pi-models|provider-bootstrap)-[1-9][0-9]*|model-router-(management|anchor)-node-[1-9][0-9]*|task-vm-upcloud-token)$']],
], 'task_vm.fill_package_cache' => [
    'workflow' => 'operation-task-vm-cache-fill',
    'projection' => TaskVmPackageCacheProjection::class,
    'workflow_inputs' => true,
    // No result step: the projector reads each batch reply and the run's step statuses; the native assert is the end check.
    'terminal_result' => ['job' => 'fill', 'step' => 'Check every archive', 'native' => true, 'captures' => TaskVmPackageCacheProjection::captures()],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-vm-cache-fill-github-[1-9][0-9]*$']],
], 'task_vm.image_build' => [
    'workflow' => 'operation-task-vm-image-build',
    'projection' => TaskVmImageProjection::class,
    'workflow_inputs' => true,
    // No result step: the projector reads step statuses, the published fingerprint and each recipe phase's start and end.
    'terminal_result' => ['job' => 'build', 'step' => 'complete', 'native' => true, 'captures' => TaskVmImageProjection::captures()],
], 'task_vm.sweep_host' => [
    'workflow' => 'operation-task-vm-sweep',
    'projection' => TaskVmSweepProjection::class,
    'workflow_inputs' => true,
    // No result step: the projector reads the admitted provider's plan; its native assert is the end check.
    'terminal_result' => ['job' => 'check', 'step' => TaskVmSweepProjection::IncusCheck, 'alternatives' => [TaskVmSweepProjection::CloudCheck], 'native' => true,
        'captures' => TaskVmSweepProjection::captures()],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-vm-upcloud-token$']],
], 'task_vm.upcloud_image_build' => [
    'workflow' => 'operation-task-vm-upcloud-image-build',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'task_vm_upcloud_image_writer',
    'projection' => TaskVmUpCloudImageProjection::class,
    'private_material' => ['upcloud_token' => 'upcloud.api', 'bootstrap' => 'task_vm.image_bootstrap', 'pi_artifact_file' => 'task_vm.image_artifact'],
    'secret_outputs' => ['bootstrap' => 'task_vm.image_bootstrap'],
]];
