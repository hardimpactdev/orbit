<?php

declare(strict_types=1);

namespace App\Infrastructure\Processes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\InstanceMigration\InstanceMigration;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Process;
use App\Support\ValidatedData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The status of a container Process (docs/reference/development-containers.md#processes). Neither Prometheus nor the Node agent sees a
 * container's units, so lists retain the latest completed `process.inspect` result and its time. The durable completion time controls
 * when a replacement read is due; lists never wait for it.
 */
final readonly class ContainerProcessObservation implements DirectOperationProjection
{
    /**
     * The latest completed inspection and its completion time survive cache eviction.
     *
     * @param  array<int, int>  $processIds
     * @return array<int, array{runtime_status: string, observed_at: ?string, runtime_error_code: ?string}>
     */
    public static function latest(array $processIds): array
    {
        $observations = [];
        $latest = Operation::query()->selectRaw('max(id)')->where('kind', 'process.inspect')->where('target_type', 'process')
            ->whereIn('target_id', $processIds)->whereIn('status', OperationStatus::terminalValues())->groupBy('target_id');
        foreach (Operation::query()->whereIn('id', $latest)->get() as $operation) {
            $state = $operation->status === OperationStatus::Succeeded
                ? ValidatedData::string($operation->result['state'] ?? 'unknown') : 'unknown';
            $observations[$operation->target_id] = [
                'runtime_status' => $state,
                'observed_at' => $operation->finished_at?->toISOString(),
                'runtime_error_code' => $state === 'unknown' ? ($operation->error_code ?? 'process.status_failed') : null,
            ];
        }

        return $observations;
    }

    /** @param array{runtime_status: string, observed_at: ?string, runtime_error_code: ?string}|null $observation */
    public static function due(?array $observation): bool
    {
        $at = $observation['observed_at'] ?? null;

        return $at === null || Carbon::parse($at)->addSeconds(PrometheusProcessRuntimeStatusIndex::ObservedSeconds)->lessThanOrEqualTo(now());
    }

    public static function pending(int $processId): bool
    {
        return Operation::query()->where('lock_key', 'process-inspect:'.$processId)->exists();
    }

    /**
     * Persist failed admission in the same source as native inspection outcomes. Only an existing inspection of this Process is exempt.
     *
     * @return array{runtime_status: string, observed_at: ?string, runtime_error_code: string}|null
     */
    public static function failed(int $processId, Throwable $error): ?array
    {
        if ($error instanceof ResourceOperationException && $error->errorCode === 'operation.target_busy' && self::pending($processId)) {
            return null;
        }
        $reason = $error instanceof ResourceOperationException ? $error->errorCode
            : ($error instanceof ValidationException ? 'validation.failed' : 'process.status_failed');
        $process = Process::query()->find($processId);
        $instance = $process !== null && Instance::isMorphType($process->owner_type) ? Instance::query()->find($process->owner_id) : null;
        $at = now();
        Operation::query()->create(['kind' => 'process.inspect', 'target_type' => 'process', 'target_id' => $processId,
            'node_id' => $instance?->node_id, 'status' => OperationStatus::Failed, 'input' => ['process_id' => $processId],
            'result' => ['state' => 'unknown'], 'error_code' => $reason, 'started_at' => $at, 'finished_at' => $at, 'triggered_by' => 'observation']);

        return ['runtime_status' => 'unknown', 'observed_at' => $at->toISOString(), 'runtime_error_code' => $reason];
    }

    /** Starts one read of the Process's unit; a read that already runs for it answers instead. */
    public function admit(int $processId): void
    {
        try {
            app(OperationIdempotency::class)->suspended(fn () => DB::transaction(function () use ($processId): void {
                $process = Process::query()->find($processId);
                if ($process === null || ! self::due(self::latest([$processId])[$processId] ?? null) || self::pending($processId)) {
                    return;
                }
                $target = app(ProcessTargetResolver::class)->forInspection($process);
                if ($target->container === null) {
                    return;
                }
                if ($target->instance !== null) {
                    InstanceMigration::assertSettled($target->instance);
                }
                app(StartOperationAction::class)->execute('process.inspect', 'process', $processId, $target->node->id, [
                    'process_id' => $processId, 'run_budget_seconds' => 300,
                    'intent' => ContainerProcessInspection::intent($process, $target, 'status', 1, (string) Str::uuid()),
                ], 'observation', null, lockKey: 'process-inspect:'.$processId);
            }));
        } catch (Throwable $error) {
            self::failed($processId, $error);
        }
    }

    public function input(Operation $operation): array
    {
        if ($operation->target_type !== 'process' || ! Process::query()->whereKey($operation->target_id)->exists()) {
            throw new ResourceOperationException('process.target_unavailable', 'The container Process is gone.', 409);
        }

        return ['intent' => ValidatedData::object($operation->input['intent']), 'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->refresh();
            $status = OperationStatus::from(ValidatedData::string($data['status']));
            $state = $status === OperationStatus::Succeeded
                ? ValidatedData::string(ValidatedData::object($data['result'] ?? [])['state'] ?? 'unknown') : 'unknown';
            $projected = app(DirectOperation::class)->project($operation, $data, ['state' => $state]);

            return $projected;
        });
    }
}
