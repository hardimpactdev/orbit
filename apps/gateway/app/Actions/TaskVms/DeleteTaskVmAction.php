<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\TaskVms\TaskVmRuntime;
use App\Models\TaskVm;
use Throwable;

/**
 * Deletes a task VM and forgets the workspace records on its Node, but keeps the Node: the row becomes
 * `destroying`, the model key is revoked, the VM and its workspace clone are deleted, and each workspace Instance
 * is recorded as removed offline. `DestroyTaskVm` then removes the Node and sets `destroyed`. A failed step stays
 * on the row as its error and is thrown. Every step repeats safely.
 */
final readonly class DeleteTaskVmAction
{
    public function __construct(
        private TaskVmRuntime $runtime,
        private TaskVmProvider $provider,
        private ForgetTaskVmWorkspacesAction $workspaces,
    ) {}

    public function execute(TaskVm $vm): void
    {
        try {
            $vm->update(['state' => TaskVmState::Destroying, 'error_code' => null, 'error_message' => null]);
            $this->runtime->release($vm);
            $this->provider->destroy($vm);
            $this->workspaces->execute($vm);
        } catch (Throwable $exception) {
            $vm->recordError($exception);

            throw $exception;
        }
    }
}
