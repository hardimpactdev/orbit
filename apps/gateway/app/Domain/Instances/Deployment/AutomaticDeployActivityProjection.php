<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Data\Instances\AutomaticDeployEventData;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Records the admitted Activity once; it performs no notifier or Node action. */
final readonly class AutomaticDeployActivityProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct, private AutomaticDeployAlerts $alerts) {}

    public function input(Operation $operation): array
    {
        return $operation->input;
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $locked = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $result = $locked->result ?? [];
            if (! $locked->status->terminal() && ($data['status'] ?? null) === 'succeeded') {
                $instance = Instance::query()->findOrFail($locked->target_id);
                $event = AutomaticDeployEventData::from(array_diff_key($locked->input, ['caller_node_id' => true]));
                $result = $this->alerts->activity($instance, new AutomaticDeployOutcome(
                    $instance->id, AutomaticDeployResult::from(ValidatedData::string($event->result)),
                    $event->commit, $event->operationId, $event->errorCode, $event->message,
                ), $locked->request_id);
            }

            return $this->direct->project($locked, $data, $result);
        });
    }
}
