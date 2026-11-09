<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Operations\StartOperationAction;
use App\Data\Tasks\StartTaskPrepData;
use App\Data\Tasks\StartTaskPrepFixData;
use App\Data\Tasks\TaskTurnData;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Proof\ProofRunIntent;
use App\Domain\Proof\ProofRunSettlement;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmImageRefreshIntent;
use App\Domain\TaskVms\TaskVmPromotedImage;
use App\Domain\TaskVms\TaskVmRuntime;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Jobs\Proof\CancelProofGroup;
use App\Jobs\Tasks\AdmitTaskVm;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskReviewRound;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/** A full task pool records a waiting admission, without a VM or identity. */
final readonly class TaskVmAdmission
{
    public const int WaitSeconds = 7200;

    public const int RetrySeconds = 20;

    public function __construct(private OperationStore $store, private StartOperationAction $start) {}

    /** @param array<string,mixed> $request
     * @param  Closure(): array<string,mixed>  $capture
     */
    public function start(Task $group, string $kind, array $request, Closure $capture, string $caller, ?string $requestId): Operation
    {
        try {
            return DB::transaction(function () use ($group, $kind, $capture, $caller, $requestId): Operation {
                if (TaskVm::forGroup($group) === null && Operation::query()->where('status', OperationStatus::Waiting)->where('target_type', 'task_group')->whereNotNull('input->capacity_request')->exists()) {
                    throw new TaskCapacityException(TaskWait::Capacity, 'An earlier task is waiting for a place.');
                }
                $input = $capture();

                return $this->start->execute($kind, 'task_group', $group->id, ValidatedData::integer($input['node_id']), $input, $caller, $requestId, lockKey: OperationStore::lockKey('task_group', $group->id));
            });
        } catch (TaskCapacityException $exception) {
            $coordinator = Node::query()->whereHas('roles', static fn ($roles) => $roles->where('role', RoleName::Swamp)->where('status', 'active'))->firstOrFail();

            $operation = $this->wait($kind, 'task_group', $group->id, $coordinator->id, $request, $caller, $requestId);
            $this->store->change($operation, ['state' => ['capacity_wait_reason' => $exception->getMessage()]]);

            return $operation;
        }
    }

    /** @param array<string,mixed> $request */
    public function wait(string $kind, string $target, int $id, int $node, array $request, string $caller, ?string $requestId): Operation
    {
        return DB::transaction(function () use ($kind, $target, $id, $node, $request, $caller, $requestId): Operation {
            $operation = $this->store->create($kind, $target, $id, $node, ['capacity_request' => $request, ...(isset($request['proof_operation_id']) ? ['proof_operation_id' => $request['proof_operation_id'], 'command' => $request['command'], 'file' => $request['file']] : [])], $caller, $requestId, OperationStore::lockKey($target, $id));
            $this->store->change($operation, ['status' => OperationStatus::Waiting, 'current_step' => $target === 'task_group' ? 'Wait for a task place' : 'Wait for a CI place']);
            if ($target === 'task_group') {
                Task::topLevel()->whereKey($id)->update(['capacity_wait_reason' => 'capacity']);
            }
            AdmitTaskVm::dispatch($operation->id)->delay(now()->addSeconds(self::RetrySeconds))->afterCommit();

            return $operation;
        });
    }

    public function admit(int $id): void
    {
        try {
            DB::transaction(function () use ($id): void {
                $operation = Operation::query()->lockForUpdate()->find($id);
                if (! $operation instanceof Operation || $operation->status !== OperationStatus::Waiting || ! isset($operation->input['capacity_request'])) {
                    return;
                }
                if ($operation->created_at?->lte(now()->subSeconds(self::WaitSeconds))) {
                    $this->fail($operation, 'tasks.capacity_timeout', 'No place became free within the two-hour admission budget.');

                    return;
                }
                if ($operation->kind === 'task_vm.image_refresh') {
                    $request = ValidatedData::object($operation->input['capacity_request']);
                    $host = Node::query()->findOrFail($operation->target_id);
                    if (($request['from_stock'] ?? false) === true) {
                        app(TaskVmPromotedImage::class)->withdraw($host, TaskVmRuntime::Vm);
                    }
                    $input = app(TaskVmImageRefreshIntent::class)->input($host, ValidatedData::nullableString($request['commit'] ?? null), ($request['from_stock'] ?? false) === true);
                    $this->store->change($operation, ['input' => $input, 'status' => OperationStatus::Queued, 'started_at' => now(), 'current_step' => null]);
                    app(SwampDispatch::class)->start($operation);

                    return;
                }
                if (Operation::query()->where('status', OperationStatus::Waiting)->where('target_type', 'task_group')->whereNotNull('input->capacity_request')->where('id', '<', $id)->exists()) {
                    throw new TaskCapacityException(TaskWait::Capacity, 'An earlier task is waiting for a place.');
                }
                $group = Task::topLevel()->lockForUpdate()->findOrFail($operation->target_id);
                app(TaskOperations::class)->requireOpenExternal($group);
                $request = ValidatedData::object($operation->input['capacity_request']);
                if (in_array($operation->kind, ['tasks.provision', 'proof.command'], true)) {
                    app(TaskProvisionIntent::class)->assertProvisionable($group);
                }
                if ($operation->kind === 'tasks.build' && ! TaskBuildIntent::waiting($group, $operation->id)) {
                    throw new ResourceOperationException('tasks.build_refused', 'The build needs a prep group whose spec is done and whose build has not started.', 409);
                }
                $input = match ($operation->kind) {
                    'proof.command' => app(ProofRunIntent::class)->captureCommand($group, $request),
                    'tasks.provision' => app(TaskProvisionIntent::class)->capture($group, ValidatedData::string($request['commit'])),
                    'tasks.prep' => app(TaskPrepIntent::class)->capture($group, new StartTaskPrepData(ValidatedData::string($request['command']), ValidatedData::nullableString($request['brief'] ?? null), ValidatedData::nullableString($request['reference'] ?? null)), ValidatedData::string($request['commit'])),
                    'tasks.build' => app(TaskBuildIntent::class)->capture($group, ValidatedData::string($request['head'])),
                    'tasks.prep_fix' => app(TaskPrepFixIntent::class)->capture($group, new StartTaskPrepFixData(ValidatedData::string($request['corrections']))),
                    'tasks.turn' => app(TaskTurnIntent::class)->captureTurn($group, TaskTurnData::from($request)),
                    'tasks.review_round' => app(TaskReviewRoundIntent::class)->capture($group, ValidatedData::string($request['head'])),
                    default => throw new \LogicException('Unknown task capacity request.'),
                };
                if ($operation->kind === 'tasks.review_round') {
                    TaskReviewRound::query()->create(['task_group_id' => $group->id, 'head_sha' => $request['head'], 'round' => $input['round'],
                        'operation_id' => $operation->id, 'check_id' => ValidatedData::object($input['check'] ?? [])['check_id'] ?? null]);
                }
                $this->store->change($operation, ['input' => $input, 'node_id' => $input['node_id'], 'status' => OperationStatus::Queued, 'started_at' => now(), 'current_step' => null]);
                app(SwampDispatch::class)->start($operation);
            });
        } catch (TaskCapacityException) {
            AdmitTaskVm::dispatch($id)->delay(now()->addSeconds(self::RetrySeconds));
        } catch (Throwable $exception) {
            report($exception);
            $operation = Operation::query()->findOrFail($id);
            if (! $operation->status->terminal()) {
                $this->fail($operation, 'tasks.admission_failed', 'The waiting task admission could not be completed.');
            }
        }
    }

    public function fail(Operation $operation, string $code, string $message): void
    {
        $this->store->change($operation, ['status' => OperationStatus::Failed, 'finished_at' => now(), 'error_code' => $code,
            'result' => ['error_code' => $code, 'message' => $message], 'lock_key' => null]);
        if ($operation->target_type === 'task_group') {
            Task::topLevel()->whereKey($operation->target_id)->update(['capacity_wait_reason' => null]);
        }
        if ($operation->kind === 'proof.command') {
            DB::afterCommit(static function () use ($operation): void {
                CancelProofGroup::dispatch($operation->id);
                app(ProofRunSettlement::class)->settle(ValidatedData::integer($operation->input['proof_operation_id']));
            });
        }
    }
}
