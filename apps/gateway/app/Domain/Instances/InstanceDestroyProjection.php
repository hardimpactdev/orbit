<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Actions\Instances\ProjectDirectInstanceDestroyAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Models\InstanceRemoval;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Records the native terminal outcome. Workers receive intent at admission. */
final readonly class InstanceDestroyProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct, private ProjectDirectInstanceDestroyAction $records) {}

    public function input(Operation $operation): array
    {
        $input = app(InstanceDestroyWorkflowInput::class)->forOperation($operation);

        return ['intent' => $input['intent'], 'execution_secrets' => $operation->status->terminal()
            ? [] : app(InstanceDestroyExecutionCopies::class)->forOperation($operation)];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $raw = ValidatedData::object($data['result'] ?? []);
            $result = array_intersect_key($raw, array_flip(['removed', 'error_code', 'failed_step']));
            $terminal = $operation->status->terminal();
            $status = $data['status'];
            if (! $terminal && isset($raw['source_inspection'])) {
                app(InstanceDestroySourceInspection::class)->capture($operation, ValidatedData::object($raw['source_inspection']));
            }
            if (! $terminal && $status === 'succeeded') {
                $result['removed'] = true;
                $this->records->execute(app(InstanceDestroySourceInspection::class)->executable($operation->input), $result);
            }
            $projected = $this->direct->project($operation, $data, $result);
            if (! $terminal && in_array($status, ['failed', 'cancelled'], true)) {
                $step = match ($result['failed_step'] ?? null) {
                    'runtime', 'teardown', 'databases' => InstanceRemovalStep::RuntimeCleanup,
                    'php', 'caddy', 'dns', 'firewall', 'cleanup' => InstanceRemovalStep::RouteTargetClear,
                    'finalize_sources' => InstanceRemovalStep::SourceFinalization,
                    'complete' => InstanceRemovalStep::RowDeletion,
                    default => InstanceRemovalStep::SourcePreparation,
                };
                InstanceRemoval::query()->whereKey($operation->input['removal_id'])->update(['status' => InstanceRemovalStatus::Failed,
                    'error_code' => $data['error_code'] ?? 'operation.cancelled', 'current_step' => $step, 'failed_step' => $step]);
            }

            return $projected;
        });
    }
}
