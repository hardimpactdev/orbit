<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\TaskVms\TaskVmRuntime;
use App\Infrastructure\TaskVms\TaskVmWorker;
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
            try {
                app(TaskVmWorker::class)->stopVm($vm);
            } catch (ResourceOperationException) {
                // VM deletion itself stops the worker when neither the guest nor power-off can do so.
            }
            $vm->update(['state' => TaskVmState::Destroying, 'error_code' => null, 'error_message' => null]);
            $this->runtime->release($vm);
            $this->provider->destroy($vm);
            $vm->update(['pi_token' => null, 'worker_stop_required' => false]);
            $this->workspaces->execute($vm);
        } catch (Throwable $exception) {
            $vm->recordError($exception);

            throw $exception;
        }
    }
}
