<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\TaskVms\StartTaskVmRuntimeOperationAction;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmState;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\TaskVms\ReadTaskVmExecutorRequest;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\TaskVms\TaskVmGuest;
use App\Infrastructure\TaskVms\TaskVmPreservation;
use App\Infrastructure\TaskVms\TaskVmRuntime;
use App\Infrastructure\TaskVms\TaskVmUpCloudImageRead;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Http\JsonResponse;
use Throwable;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class TaskVmExecutorReadController extends Controller
{
    public function upcloudImage(ReadTaskVmExecutorRequest $request, Operation $operation, TaskVmUpCloudImageRead $images, ?string $view = null): JsonResponse
    {
        return response()->json(['data' => $images->read($operation, $view), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }

    public function lifecycle(ReadTaskVmExecutorRequest $request, Operation $operation, TaskVmGuest $guest, TaskVmRuntime $runtime, StartTaskVmRuntimeOperationAction $intent): JsonResponse
    {
        if (! str_starts_with($operation->kind, 'task_vm.')) {
            throw new ResourceOperationException('operation.execution_forbidden', 'A task VM Operation is required.', 422);
        }
        $vm = TaskVm::query()->findOrFail(ValidatedData::integer($operation->input['task_vm_id']));
        $power = $guest->observeSettled($vm);
        $provisioning = $vm->state === TaskVmState::Provisioning;

        return response()->json(['data' => [
            'state' => $vm->state->value, 'node_id' => $vm->node_id, 'present' => $power !== null,
            'running' => $power?->running, 'settled' => $power?->settled, 'address' => $power?->address,
            'image_matches' => $vm->provider !== TaskVmProviderKind::Incus || $vm->recipe_identity === null || $power?->imageFingerprint === $vm->image_fingerprint,
            'bootstrap_ready' => $provisioning && $power?->running === true ? $guest->bootstrapReady($vm) : false,
            'worker_stop_required' => $vm->worker_stop_required, ...app(TaskVmPreservation::class)->read($vm, $power),
            'responds' => $runtime->responds($vm),
            'runtime' => $vm->node_id !== null && $vm->model_key !== null ? $intent->snapshot($vm) : null,
        ], 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }

    public function runtime(ReadTaskVmExecutorRequest $request, Operation $operation, AgentCommandRunner $agents, TaskVmRuntime $runtime): JsonResponse
    {
        if (! str_starts_with($operation->kind, 'task_vm.')) {
            throw new ResourceOperationException('operation.execution_forbidden', 'A task VM Operation is required.', 422);
        }
        $vm = TaskVm::query()->findOrFail(ValidatedData::integer($operation->input['task_vm_id']));
        $current = null;
        if ($vm->node !== null) {
            try {
                $current = $agents->run($vm->node, 'pi.inspect-artifact', ['user' => $vm->node->user, 'sha256' => $operation->input['sha256']])['current'] ?? null;
            } catch (Throwable) {
                // An unreadable installation remains unknown; it never authorizes an upload.
            }
        }

        return response()->json(['data' => ['pi_current' => is_bool($current) ? $current : null, 'responds' => $runtime->responds($vm)],
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }
}
