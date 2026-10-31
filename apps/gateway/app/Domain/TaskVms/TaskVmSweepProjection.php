<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Actions\TaskVms\FailTaskVmAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Infrastructure\TaskVms\TaskVmHostAudit;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use App\Models\UpCloudTaskVmImageBuild;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Safe current ownership reads and projection of native host observations; no host/provider calls. */
final readonly class TaskVmSweepProjection implements DirectOperationProjection
{
    /** @return list<string|array<string,mixed>> */
    public static function resultFields(): array
    {
        return ['ready', 'error_code', ...array_map(static fn (string $name): array => ['name' => $name, 'type' => 'list', 'items' => 'string', 'max' => 4096], ['observed_names', 'deleted_names'])];
    }

    public function input(Operation $operation): array
    {
        $input = $operation->input;
        if ($operation->kind !== 'task_vm.sweep_host' || $operation->target_type !== 'task_vm_sweep'
            || $operation->target_id !== $operation->node_id || ($input['node_id'] ?? null) !== $operation->node_id
            || ! in_array($input['provider'] ?? null, ['incus', 'upcloud'], true)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'A sweep needs its admitted provider and exact Node.', 422);
        }
        $query = TaskVm::query()->live()->where('provider', $input['provider']);
        if ($input['provider'] === 'incus') {
            $query->where('host_node_id', $operation->node_id);
        }
        unset($input['upcloud_token']);
        $input['stray_vm_ids'] = $input['provider'] === 'incus'
            ? array_map(static fn (TaskVm $vm): int => $vm->id, TaskVmHostAudit::strayRecords(Node::query()->findOrFail($operation->node_id))) : [];
        $input['live_names'] = $query->orderBy('id')->pluck('name')->all();
        $input['protected_build_ids'] = TaskVmImageBuild::query()->where('host_node_id', $operation->node_id)
            ->where(fn ($query) => $query->where('state', TaskVmImageBuildState::Running)
                ->orWhere(fn ($query) => $query->where('state', TaskVmImageBuildState::Failed)->whereNull('cleanup_started_at')
                    ->where('updated_at', '>', now()->subHours(24))))->orderBy('id')->pluck('id')->all();
        $input['running_stamps'] = UpCloudTaskVmImageBuild::query()->where('state', TaskVmImageBuildState::Running)
            ->where('started_at', '>', now()->subSeconds(UpCloudTaskVmImageBuild::RunningSeconds))
            ->get()->map(static fn (UpCloudTaskVmImageBuild $build): ?string => $build->intent['stamp'] ?? null)->filter()->values()->all();

        return $input;
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $this->input($operation);
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $result = ValidatedData::object($data['result'] ?? []);
        $code = $data['error_code'] ?? null;
        $observe = $status === OperationStatus::Succeeded || $status === OperationStatus::Failed;
        if (($status === OperationStatus::Succeeded && ($code !== null || ($result['ready'] ?? null) !== true
                    || (count($result) !== 3 || array_diff(array_keys($result), ['ready', 'observed_names', 'deleted_names']) !== [])))
            || ($status === OperationStatus::Failed && ($code !== 'task_vm.host_command_failed' || ($result['error_code'] ?? null) !== $code
                    || (count($result) !== 3 || array_diff(array_keys($result), ['error_code', 'observed_names', 'deleted_names']) !== [])))
            || (! $observe && (! in_array($status, [OperationStatus::Running, OperationStatus::Cancelled], true) || $result !== [] || $code !== null))) {
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
            $projected = app(DirectOperation::class)->project($operation, $data, $result, 'sweep-host');
            if ($observe) {
                $query = TaskVm::query()->live()->where('provider', $operation->input['provider']);
                if ($operation->input['provider'] === 'incus') {
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

    private function refuse(): never
    {
        throw new ResourceOperationException('operation.result_invalid', 'A sweep requires bounded observed and deleted resource names.', 422);
    }
}
