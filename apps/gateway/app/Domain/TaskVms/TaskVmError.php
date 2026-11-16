<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/**
 * Stable Gateway `task_vm.*` error codes. Logs and the API carry the value.
 * {@see TaskVmException} takes a case. Native command refusals belong to the exported agent contract.
 */
enum TaskVmError: string
{
    // The group's runtime on the VM.
    case GitCredentialRefused = 'task_vm.git_credential_refused';

    // Workspace ownership.
    case WorkspaceMismatch = 'task_vm.workspace_mismatch';
}
