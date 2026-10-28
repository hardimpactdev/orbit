<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Data\Instances\InstanceSmokeReceiptData;
use App\Data\Instances\InstanceSmokeResultData;
use App\Data\Operations\OperationData;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;

final readonly class ReadInstanceSmokeReceiptAction
{
    public function execute(Instance $instance, Node $caller, string $key): InstanceSmokeReceiptData
    {
        $operation = Operation::query()->where('kind', OperationKinds::InstanceSmoke)->where('idempotency_key', $key)->first();
        if ($operation === null) {
            return new InstanceSmokeReceiptData($key, false, null, null);
        }
        if ($operation->target_type !== 'instance_smoke' || $operation->target_id !== $instance->id || ($operation
            ->input['caller_node_id'] ?? null) !== $caller->id) {
            throw new ResourceOperationException('operation.idempotency_conflict',
                'This smoke key belongs to another caller or Instance.', 409);
        }
        $result = $operation->state['smoke'] ?? [];
        if ($result === [] && $operation->status->terminal()) {
            $result = ['state' => 'unknown'];
        }
        $smoke = InstanceSmokeResultData::from([...$operation->input, ...(is_array($result) ? $result : [])]);

        return new InstanceSmokeReceiptData($key, true, OperationData::fromModel($operation), $smoke);
    }
}
