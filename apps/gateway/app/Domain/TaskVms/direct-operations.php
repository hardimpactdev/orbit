<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmConvergeProjection;
use App\Domain\TaskVms\TaskVmDestroyProjection;
use App\Domain\TaskVms\TaskVmFleetBuildProjection;
use App\Domain\TaskVms\TaskVmHubProjection;
use App\Domain\TaskVms\TaskVmImageProjection;
use App\Domain\TaskVms\TaskVmImageRefreshProjection;
use App\Domain\TaskVms\TaskVmPackageCacheProjection;
use App\Domain\TaskVms\TaskVmProvisionProjection;
use App\Domain\TaskVms\TaskVmReadyCopiesProjection;
use App\Domain\TaskVms\TaskVmResumeProjection;
use App\Domain\TaskVms\TaskVmSlotEnrolProjection;
use App\Domain\TaskVms\TaskVmSweepProjection;
use App\Domain\TaskVms\TaskVmUpCloudImageProjection;

$provision = [
    'workflow' => 'operation-task-vm-provision',
    'projection' => TaskVmProvisionProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'provision', 'step' => 'result'],
    'private_material' => [],
    'secret_outputs' => [],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^(task-vm-(model-key|pi-token|pi-models|provider-bootstrap)-[1-9][0-9]*|model-router-(management|anchor)-node-[1-9][0-9]*|task-vm-upcloud-token|agent-secret-node-[1-9][0-9]*)$']],
];

return ['task_vm.hub_converge' => [
    'workflow' => 'operation-task-vm-hub-converge',
    'workflow_inputs' => true,
    // No result step: the projector reads the end check's status and builds the result from the captured intent.
    'terminal_result' => ['job' => 'hub', 'step' => TaskVmHubProjection::EndCheck, 'native' => true],
    'projection' => TaskVmHubProjection::class,
], 'task_vm.converge' => [
    'workflow' => 'operation-task-vm-converge',
    'projection' => TaskVmConvergeProjection::class,
    'workflow_inputs' => true,
    // No result step: the projector reads the check phase's status and builds the result from the captured intent.
    'terminal_result' => ['job' => 'check', 'step' => TaskVmConvergeProjection::EndCheck, 'native' => true],
], 'task_vm.resume' => [
    'workflow' => 'operation-task-vm-resume',
    'projection' => TaskVmResumeProjection::class,
    'workflow_inputs' => true,
    // No result step: the projector reads the run's step statuses; the native assert is the end check.
    'terminal_result' => ['job' => 'check', 'step' => 'Check the guest is ready', 'native' => true],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-vm-upcloud-token$']],
], 'task_vm.destroy' => [
    'workflow' => 'operation-task-vm-destroy',
    'projection' => TaskVmDestroyProjection::class,
    'workflow_inputs' => true,
    // No result step: the projector reads the run's step statuses and builds the cleanup receipt from the captured intent.
    'terminal_result' => ['job' => 'destroy', 'step' => 'Destroy the task VM', 'native' => true],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^(task-vm-model-key-[1-9][0-9]*|model-router-(management|anchor)-node-[1-9][0-9]*|task-vm-upcloud-token)$']],
], 'task_vm.provision' => $provision, 'task_vm.slot_enrol' => [
    // A ready slot (docs/cli/tasks/vm-slots.mmd): the VM child of task_vm.provision, the converge and a stop.
    ...$provision, 'workflow' => 'operation-task-vm-slot-enrol', 'projection' => TaskVmSlotEnrolProjection::class,
], 'task_vm.image_refresh' => [
    'workflow' => 'operation-task-vm-image-refresh',
    'projection' => TaskVmImageRefreshProjection::class,
    'workflow_inputs' => true,
    // No result step: the projector reads the promotion's status and the aliases it printed.
    'terminal_result' => ['job' => 'refresh', 'step' => TaskVmImageRefreshProjection::EndCheck, 'native' => true, 'captures' => TaskVmImageRefreshProjection::captures()],
    // The proof Swamp Club key, which the nested Gateway's Swamp runs with while the refresh converges it.
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^swamp-proof-club-api-key$']],
], 'task_vm.fleet_build' => [
    'workflow' => 'operation-task-vm-fleet-build',
    'projection' => TaskVmFleetBuildProjection::class,
    'workflow_inputs' => true,
    // No result step: the projector reads the run's step statuses; the Ansible wait that asserts the nested Doctor is the end check.
    'terminal_result' => ['job' => 'end', 'step' => TaskVmFleetBuildProjection::EndCheck, 'native' => true],
    // The proof Swamp Club key, which the new Gateway's Swamp runs with.
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^swamp-proof-club-api-key$']],
], 'task_vm.ready_copies' => [
    'workflow' => 'operation-task-vm-ready-copies',
    'projection' => TaskVmReadyCopiesProjection::class,
    'workflow_inputs' => true,
    // No result step: the projector reads the run's step statuses; the native assert is the end check.
    'terminal_result' => ['job' => 'end', 'step' => TaskVmReadyCopiesProjection::EndCheck, 'native' => true],
    // The proof Swamp Club key, which the scan looks for in the guests.
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^swamp-proof-club-api-key$']],
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
    // No result step: the projector reads the step statuses and the fingerprint that the publish step printed.
    'terminal_result' => ['job' => 'publish', 'step' => TaskVmImageProjection::EndCheck, 'native' => true],
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
    'projection' => TaskVmUpCloudImageProjection::class,
    'workflow_inputs' => true,
    // No result step: the projector reads the template UUID and the run's step statuses; the native assert is the end check.
    'terminal_result' => ['job' => 'check', 'step' => TaskVmUpCloudImageProjection::EndCheck, 'native' => true, 'captures' => TaskVmUpCloudImageProjection::captures()],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^(task-vm-upcloud-token|task-vm-image-bootstrap-[1-9][0-9]*)$']],
]];
