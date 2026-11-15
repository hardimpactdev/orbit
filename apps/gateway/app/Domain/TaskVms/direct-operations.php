<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmResumeProjection;
use App\Domain\TaskVms\TaskVmSlotReleaseProjection;

// The kinds of a task VM's life that the Pi task loop still captures (docs/reference/old-paths.md). The image refresh and the hub
// converge are removed; their old Operations stay as history ({@see \App\Domain\Operations\RetiredOperationSettlement}).
return ['task_vm.resume' => [
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
]];
