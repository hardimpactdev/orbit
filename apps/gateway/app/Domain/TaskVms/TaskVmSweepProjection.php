<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Actions\TaskVms\FailTaskVmAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Safe current ownership reads and projection of native host observations; no host/provider calls. */
final readonly class TaskVmSweepProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        $input = $operation->input;
        if ($operation->kind !== 'task_vm.sweep_host' || $operation->target_type !== 'task_vm_sweep'
            || $operation->target_id !== $operation->node_id || ($input['node_id'] ?? null) !== $operation->node_id
            || ! in_array($input['provider'] ?? null, ['incus', 'upcloud'], true)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'A sweep needs its admitted provider and exact Node.', 422);
        }

        return TaskVmProviderWorkflowInput::envelope($input);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $this->input($operation);
        if ($data['status'] === OperationStatus::Interrupted->value) {
            $data['status'] = OperationStatus::Failed->value;
        }
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $result = ValidatedData::object($data['result'] ?? []);
        $observe = $status === OperationStatus::Succeeded;
        if ($observe && (($result['ready'] ?? null) !== true || ! isset($result['observed_names'], $result['deleted_names']))) {
            $this->refuse();
        }
        $names = $observe ? ValidatedData::stringList($result['observed_names']) : [];
        $deleted = $observe ? ValidatedData::stringList($result['deleted_names']) : [];
        foreach ([$names, $deleted] as $list) {
            if (count($list) > 4096 || count(array_unique($list)) !== count($list)
                || array_any($list, static fn (string $name): bool => preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,253}\z/D', $name) !== 1)) {
                $this->refuse();
            }
        }

        return DB::transaction(function () use ($operation, $data, $result, $observe, $names): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $terminal = $operation->status->terminal();
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if ($observe && ! $terminal) {
                $query = TaskVm::query()->live()->where('provider', $operation->input['provider'])
                    ->whereIn('name', ValidatedData::stringList(ValidatedData::object($operation->input['scope'])['live_names']));
                if ($operation->input['provider'] === 'incus') {
                    $this->completeImageCleanup($operation, $names);
                    $query->where('host_node_id', $operation->node_id);
                }
                foreach ($query->get() as $vm) {
                    $key = 'task-vms:sweep-missing:'.$vm->id;
                    if (in_array($vm->name, $names, true) || ! in_array($vm->state, [TaskVmState::Ready, TaskVmState::Parked], true)) {
                        Cache::forget($key);

                        continue;
                    }
                    $previous = Cache::get($key);
                    if ($previous === $operation->id) {
                        continue;
                    }
                    if (is_int($previous)) {
                        $message = "Task VM [{$vm->name}] is gone from its host.";
                        app(FailTaskVmAction::class)->execute($vm, [TaskVmState::Ready, TaskVmState::Parked], 'task_vm.vm_missing', $message, TaskVmFailedException::stoppedReason('task_vm.vm_missing', $message));
                    }
                    Cache::put($key, $operation->id, 3600);
                }
            }

            return $projected;
        });
    }

    /** @param list<string> $observed */
    private function completeImageCleanup(Operation $operation, array $observed): void
    {
        $scope = ValidatedData::object($operation->input['scope']);
        foreach (TaskVmImageBuild::query()->where('host_node_id', $operation->node_id)
            ->whereIn('id', ValidatedData::integerList($scope['retired_build_ids']))
            ->where('state', TaskVmImageBuildState::Failed)->whereNotNull('cleanup_started_at')
            ->where('cleanup_started_at', '<=', $operation->created_at)->lockForUpdate()->get() as $build) {
            $pattern = '/\Atvm-image-'.$build->id.'(?:-attempt-[0-9]+)?(?:-smoke)?\z/D';
            if (! array_any($observed, static fn (string $name): bool => preg_match($pattern, $name) === 1)) {
                $build->forceFill(['cleanup_started_at' => null])->save();
            }
        }
    }

    private function refuse(): never
    {
        throw new ResourceOperationException('operation.result_invalid', 'A sweep requires bounded observed and deleted resource names.', 422);
    }
}
