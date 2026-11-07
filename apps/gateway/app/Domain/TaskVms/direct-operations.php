<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmHubProjection;
use App\Domain\TaskVms\TaskVmImageRefreshProjection;
use App\Domain\TaskVms\TaskVmResumeProjection;
use App\Domain\TaskVms\TaskVmSlotReleaseProjection;

// What a task start writes onto its new VM (docs/cli/tasks/provision.mmd): the slot's identity, and the claim with its Pi token and the Pi
// models file that holds the fixed model key; and the proof Swamp Club key, which a refresh gives the base VM.
$taskStart = [['vault' => 'orbit-control-inputs', 'key_pattern' => '^(swamp-proof-club-api-key|task-vm-slot-(wireguard|ssh-host|agent)-[1-9][0-9]*)$'],
    ['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-vm-pi-(claim|token)-[1-9][0-9]*$']];

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
    'execution_copies' => [],
], 'task_vm.slot_release' => [
    // The delete takes no secret.
    'workflow' => 'operation-task-vm-slot-release', 'projection' => TaskVmSlotReleaseProjection::class, 'workflow_inputs' => true,
    'terminal_result' => ['job' => 'release', 'step' => 'Delete the VM', 'native' => true],
], 'task_vm.image_refresh' => [
    'workflow' => 'operation-task-vm-image-refresh',
    'projection' => TaskVmImageRefreshProjection::class,
    'workflow_inputs' => true,
    // No result step: the projector reads the prune's status and promotes the snapshot the run took.
    'terminal_result' => ['job' => 'refresh', 'step' => TaskVmImageRefreshProjection::EndCheck, 'native' => true],
    // The proof Swamp Club key, which the nested Gateway's Swamp runs with while the refresh converges it, and what the task start that
    // proves the candidate writes.
    'execution_copies' => $taskStart,
]];
