<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmDestroyProjection;
use App\Domain\TaskVms\TaskVmHubProjection;
use App\Domain\TaskVms\TaskVmImageProjection;
use App\Domain\TaskVms\TaskVmPackageCacheProjection;
use App\Domain\TaskVms\TaskVmProvisionProjection;
use App\Domain\TaskVms\TaskVmResumeProjection;
use App\Domain\TaskVms\TaskVmSeedRefreshProjection;
use App\Domain\TaskVms\TaskVmSlotEnrollProjection;
use App\Domain\TaskVms\TaskVmSlotReleaseProjection;
use App\Domain\TaskVms\TaskVmSweepProjection;
use App\Domain\TaskVms\TaskVmUpCloudImageProjection;

return ['task_vm.hub_converge' => [
    'workflow' => 'operation-task-vm-hub-converge',
    'workflow_inputs' => true,
    // No result step: the projector reads the end check's status and builds the result from the captured intent.
    'terminal_result' => ['job' => 'hub', 'step' => TaskVmHubProjection::EndCheck, 'native' => true],
    'projection' => TaskVmHubProjection::class,
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
], 'task_vm.provision' => [
    'workflow' => 'operation-task-vm-provision',
    'projection' => TaskVmProvisionProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'provision', 'step' => 'result'],
    'private_material' => [],
    'secret_outputs' => [],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^(task-vm-(model-key|pi-token|pi-models|provider-bootstrap)-[1-9][0-9]*|model-router-(management|anchor)-node-[1-9][0-9]*|task-vm-upcloud-token)$']],
], 'task_vm.slot_enroll' => [
    'workflow' => 'operation-task-vm-slot-enroll',
    'projection' => TaskVmSlotEnrollProjection::class,
    'workflow_inputs' => true,
    // No result step: the projector reads the end check's status and the proof VM's own identity report, captured below.
    'terminal_result' => ['job' => 'check', 'step' => TaskVmSlotEnrollProjection::EndCheck, 'native' => true, 'captures' => TaskVmSlotEnrollProjection::captures()],
    // The Pi token and the model key of the slot, and the router credentials that issue the key.
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^(task-vm-model-key-[1-9][0-9]*|model-router-(management|anchor)-node-[1-9][0-9]*|task-vm-upcloud-token)$'],
        ['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-vm-pi-token-[1-9][0-9]*$']],
], 'task_vm.slot_release' => [
    'workflow' => 'operation-task-vm-slot-release',
    'projection' => TaskVmSlotReleaseProjection::class,
    'workflow_inputs' => true,
    // No result step: the projector reads the end check's status. The release rotates no secret, so it has no execution copy.
    'terminal_result' => ['job' => 'check', 'step' => TaskVmSlotReleaseProjection::EndCheck, 'native' => true],
], 'task_vm.seed_refresh' => [
    'workflow' => 'operation-task-vm-seed-refresh',
    'projection' => TaskVmSeedRefreshProjection::class,
    'workflow_inputs' => true,
    // No result step: the projector reads the end check's status and the two reports that the build and the promotion print.
    'terminal_result' => ['job' => 'check', 'step' => TaskVmSeedRefreshProjection::EndCheck, 'native' => true, 'captures' => TaskVmSeedRefreshProjection::captures()],
    // The GitHub read header of the release repository, and the proof Swamp Club key.
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-vm-seed-refresh-git-[1-9][0-9]*$'],
        ['vault' => 'orbit-control-inputs', 'key_pattern' => '^swamp-proof-club-api-key$']],
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
    'projection' => TaskVmUpCloudImageProjection::class,
    'workflow_inputs' => true,
    // No result step: the projector reads the template UUID and the run's step statuses; the native assert is the end check.
    'terminal_result' => ['job' => 'check', 'step' => TaskVmUpCloudImageProjection::EndCheck, 'native' => true, 'captures' => TaskVmUpCloudImageProjection::captures()],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^(task-vm-upcloud-token|task-vm-image-bootstrap-[1-9][0-9]*)$']],
]];
