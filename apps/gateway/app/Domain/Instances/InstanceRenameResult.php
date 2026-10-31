<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Data\Instances\InstanceData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Swamp verifies the checkout; the Gateway stores the selected branch. */
final readonly class InstanceRenameResult implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct, private RecordEventBroadcaster $broadcaster) {}

    public function input(Operation $operation): array
    {
        return $operation->input;
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $terminal = $operation->refresh()->status->terminal();
            $status = OperationStatus::from(ValidatedData::string($data['status']));
            $result = Arr::only(ValidatedData::object($data['result'] ?? []), ['branch', 'error_code']);
            $projected = $this->direct->project($operation, $data, $result);
            if (! $terminal && $status === OperationStatus::Succeeded) {
                $instance = Instance::query()->findOrFail($operation->target_id);
                if ($operation->input['branch'] !== null) {
                    $branch = ValidatedData::nullableString($result['branch'] ?? null);
                    $instance->update(['branch' => $branch, 'branch_override' => $branch]);
                }
                $this->broadcaster->broadcast(RecordEventType::InstanceUpdated, $instance->id,
                    InstanceData::fromModel($instance->load(['project', 'node', 'routes.targets']))->toArray());
            }

            return $projected;
        });
    }
}
