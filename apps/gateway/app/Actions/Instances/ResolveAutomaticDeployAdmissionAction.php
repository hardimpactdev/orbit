<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Data\Instances\AutomaticDeployReceiptData;
use App\Domain\Instances\Deployment\AutomaticDeployHistory;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStore;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Support\Facades\DB;

/** Fences an absent automatic request without starting or replacing a deployment. */
final readonly class ResolveAutomaticDeployAdmissionAction
{
    public function __construct(private ReadAutomaticDeployReceiptAction $receipts, private OperationStore $operations) {}

    public function execute(Instance $instance, Node $caller, string $key, string $commit, string $requestId): AutomaticDeployReceiptData
    {
        return DB::transaction(function () use ($instance, $caller, $key, $commit, $requestId): AutomaticDeployReceiptData {
            $instance = Instance::query()->lockForUpdate()->findOrFail($instance->id);
            $receipt = $this->receipts->execute($instance, $caller, $key, $commit);
            if ($receipt->found) {
                return $receipt;
            }
            $this->operations->fenceAdmission(
                OperationKinds::InstanceDeploy, $key, $this->receipts->fingerprint($instance, $caller, $commit),
                OperationKinds::InstanceTarget, $instance->id, $instance->node_id,
                ['commit' => $commit, 'caller_node_id' => $caller->id], AutomaticDeployHistory::SwampTrigger, $requestId,
            );

            return $this->receipts->execute($instance, $caller, $key, $commit);
        });
    }
}
