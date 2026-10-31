<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Operations\StartOperationAction;
use App\Data\Instances\AutomaticDeployEventData;
use App\Domain\Instances\Deployment\AutomaticDeployHistory;
use App\Domain\Instances\Deployment\AutomaticDeployPolicyWriter;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;

final readonly class StartAutomaticDeployEventAction
{
    public function __construct(private StartOperationAction $operations, private AutomaticDeployPolicyWriter $writer) {}

    public function execute(Instance $instance, Node $caller, AutomaticDeployEventData $data, string $requestId): Operation
    {
        $this->writer->assertSwamp($instance);
        if ($data->event !== 'activity') {
            throw new ResourceOperationException('operation.kind_unavailable', 'This event has no native notification workflow.', 503);
        }

        return $this->operations->execute(OperationKinds::InstancePolicyEvent, 'instance_policy', $instance->id, $caller->id, [...$data->toArray(), 'caller_node_id' => $caller->id], AutomaticDeployHistory::SwampTrigger, $requestId);
    }
}
