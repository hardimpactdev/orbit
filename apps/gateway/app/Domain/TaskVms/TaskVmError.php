<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/**
 * Stable Gateway `task_vm.*` error codes. Rows, logs, and the API carry the value.
 * {@see TaskVmException} takes a case. Native command refusals belong to the exported agent contract.
 */
enum TaskVmError: string
{
    // Configuration and hosts.
    case InvalidConfig = 'task_vm.invalid_config';
    case UnknownHost = 'task_vm.unknown_host';
    case HostConfigured = 'task_vm.host_configured';
    case FleetUnavailable = 'task_vm.fleet_unavailable';
    case SetupFailed = 'task_vm.setup_failed';

    // The VM lifecycle.
    case JobFailed = 'task_vm.job_failed';
    case VmMissing = 'task_vm.vm_missing';
    case NotSettled = 'task_vm.not_settled';
    case AwaitingHostSweep = 'task_vm.awaiting_host_sweep';

    // The group's runtime on the VM.
    case GitCredentialRefused = 'task_vm.git_credential_refused';

    // Workspace ownership.
    case WorkspaceMismatch = 'task_vm.workspace_mismatch';
    case Parked = 'task_vm.parked';
}
