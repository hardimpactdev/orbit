<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStepStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\OperationStep;
use App\Support\ValidatedData;
use Illuminate\Support\Carbon;

/** Projects native Swamp run status. No ownership lease or execution decisions. */
final readonly class RoutePublicationProjection
{
    public function __construct(private OperationStore $store, private OperationExecutionContract $contract) {}

    /** @param array<string, mixed> $data */
    public function apply(Operation $operation, array $data): Operation
    {
        app(DirectRoutePublication::class)->assertOperation($operation);
        $status = OperationStatus::from(ValidatedData::string($data['status'] ?? 'running'));
        $result = $this->contract->result($operation, ValidatedData::object($data['result'] ?? []));
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        if ($status === OperationStatus::Succeeded && ($result['ready'] ?? false) !== true) {
            throw new ResourceOperationException('operation.result_invalid', 'The publication has no successful Swamp result.', 422);
        }
        if ($status === OperationStatus::Failed && ($code === null || ($result['error_code'] ?? null) !== $code)) {
            throw new ResourceOperationException('operation.result_invalid', 'The failed publication needs its refusal code.', 422);
        }
        if (isset($result['stage']) && ! in_array($result['stage'], ['render', 'validate'], true)) {
            throw new ResourceOperationException('operation.result_invalid', 'Only a known Caddy refusal stage can be projected.', 422);
        }
        $operation->refresh();
        if ($operation->status->terminal()) {
            if ($operation->status !== $status || ($operation->result ?? []) !== $result || $operation->error_code !== $code) {
                throw new ResourceOperationException('operation.completion_conflict', 'The Operation has another terminal result.', 409);
            }

            return $operation;
        }
        $stepName = $operation->kind === 'route.caddy_converge' ? 'caddy-publish' : 'private-dns-publish';
        $step = $operation->steps()->where('name', $stepName)->first() ?? new OperationStep([
            'operation_id' => $operation->id, 'position' => 1, 'name' => $stepName, 'resumable' => true,
        ]);
        $startedAt = Carbon::parse(ValidatedData::string($data['started_at']));
        $finishedAt = $status->terminal() ? Carbon::parse(ValidatedData::string($data['finished_at'] ?? '')) : null;
        $state = [...($operation->state ?? []), 'swamp_run_id' => $data['run_id']];
        if ($status === OperationStatus::Succeeded) {
            $state['steps'] = [$stepName => $result];
        }
        $this->store->change($operation, [
            'status' => $status, 'started_at' => $operation->started_at ?? $startedAt, 'finished_at' => $finishedAt,
            'current_step' => $status->terminal() ? null : $stepName, 'failed_step' => $status === OperationStatus::Failed ? $stepName : null,
            'error_code' => $code, 'result' => $status->terminal() ? $result : null, 'state' => $state,
        ], $step, [
            'status' => OperationStepStatus::from($status->value), 'started_at' => $startedAt, 'finished_at' => $finishedAt,
            'error_code' => $code,
        ]);

        return $operation->refresh();
    }
}
