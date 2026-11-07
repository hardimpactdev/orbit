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
use App\Support\ValidatedData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Safe current ownership reads and projection of native host observations; no host/provider calls. */
final readonly class TaskVmSweepProjection implements DirectOperationProjection
{
    public const string CloudCheck = 'Check the cloud removals';

    /** The plan step: the names it deletes and the names that stay once its end check passes.
     * @return array<string,array{job:string,step:string}>
     */
    public static function captures(): array
    {
        return ['upcloud' => ['job' => 'inventory', 'step' => 'Plan the cloud removals']];
    }

    public function input(Operation $operation): array
    {
        $input = $operation->input;
        if ($operation->kind !== 'task_vm.sweep_host' || $operation->target_type !== 'task_vm_sweep'
            || $operation->target_id !== $operation->node_id || ($input['node_id'] ?? null) !== $operation->node_id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'A sweep needs its exact Node.', 422);
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
        $observe = $status === OperationStatus::Succeeded;
        $plan = ValidatedData::object($data['result'] ?? [])['upcloud'] ?? null;
        if ($observe && (! is_array($plan) || ! isset($plan['observed_names'], $plan['deleted_names']))) {
            $this->refuse();
        }
        $names = $observe ? ValidatedData::stringList($plan['observed_names']) : [];
        $deleted = $observe ? ValidatedData::stringList($plan['deleted_names']) : [];
        foreach ([$names, $deleted] as $list) {
            if (count($list) > 4096 || count(array_unique($list)) !== count($list)
                || array_any($list, static fn (string $name): bool => preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,253}\z/D', $name) !== 1)) {
                $this->refuse();
            }
        }

        $code = $status === OperationStatus::Failed ? TaskVmError::HostCommandFailed->value : null;
        $result = $observe ? ['ready' => true, 'observed_names' => $names, 'deleted_names' => $deleted] : ($code !== null ? ['error_code' => $code] : []);

        return DB::transaction(function () use ($operation, $data, $code, $result, $observe, $names): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $terminal = $operation->status->terminal();
            $projected = app(DirectOperation::class)->project($operation, [...$data, 'error_code' => $code], $result);
            if ($observe && ! $terminal) {
                $query = TaskVm::query()->live()->where('provider', TaskVmProviderKind::UpCloud)
                    ->whereIn('name', ValidatedData::stringList(ValidatedData::object($operation->input['scope'])['live_names']));
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

    private function refuse(): never
    {
        throw new ResourceOperationException('operation.result_invalid', 'A sweep requires bounded observed and deleted resource names.', 422);
    }
}
