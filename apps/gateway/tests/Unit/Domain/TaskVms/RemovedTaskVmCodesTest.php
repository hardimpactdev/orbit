<?php

declare(strict_types=1);

use App\Domain\Doctor\NodeDoctorIssueCode;
use App\Domain\Tasks\TaskWait;
use App\Domain\TaskVms\TaskVmError;

/** The task VM error and doctor codes that nothing produced or read are gone (docs/reference/old-paths.md#the-task-vm-lifecycle). */
it('holds no task VM error code that nothing produces', function (string $code): void {
    expect(TaskVmError::tryFrom($code))->toBeNull();
})->with(array_map(static fn (string $name): string => "task_vm.{$name}", [
    'host_in_use', 'range_in_use', 'setup_unavailable', 'pool_start_timeout', 'park_failed', 'resume_timeout', 'invalid_user_data',
    'invalid_gateway_key', 'host_command_failed', 'invalid_host_output', 'host_key_failed', 'topology_failed', 'project_unsupported',
    'image_mismatch', 'image_check_failed', 'image_clean_failed', 'image_identity_present', 'topology_seed_missing', 'topology_seed_failed',
    'base_image_missing', 'image_sync_failed', 'workers_failed',
    // Only the dropped task VM model produced these (docs/reference/old-paths.md#the-task-vm-tables).
    'invalid_config', 'unknown_host', 'host_configured', 'fleet_unavailable', 'setup_failed', 'job_failed', 'vm_missing', 'not_settled',
    'awaiting_host_sweep',
]));

it('keeps the task VM error codes that a refusal still produces', function (): void {
    expect(array_column(TaskVmError::cases(), 'value'))->toBe(['task_vm.git_credential_refused', 'task_vm.workspace_mismatch']);
});

it('has no task wait for a task VM, which nothing produces or reads', function (): void {
    expect(TaskWait::tryFrom('task_vm'))->toBeNull()->and(array_column(TaskWait::cases(), 'value'))->toBe(['capacity', 'topology']);
});

it('holds no task VM doctor code that nothing reports', function (string $code): void {
    expect(NodeDoctorIssueCode::tryFrom($code))->toBeNull();
})->with(array_map(static fn (string $name): string => "node.task_vm_{$name}", [
    'orphaned', 'missing', 'inspection_failed', 'stray_instance', 'stray_volume', 'stray_record', 'stray_node',
]));
