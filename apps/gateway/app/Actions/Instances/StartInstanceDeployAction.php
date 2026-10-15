<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationKinds;
use App\Models\Instance;
use App\Models\Operation;

/**
 * Starts the `instance.deploy` operation (docs/reference/deployments.md#the-deploy-operation). It refuses an Instance
 * that cannot be deployed before it records anything; the job then runs the deployment on the Gateway queue.
 */
final readonly class StartInstanceDeployAction
{
    public function __construct(
        private DeployInstanceAction $deploy,
        private StartOperationAction $operations,
    ) {}

    public function execute(Instance $instance, string $triggeredBy, ?string $requestId): Operation
    {
        $this->operations->assertAvailable();
        $this->deploy->plan($instance);

        return $this->operations->execute(OperationKinds::InstanceDeploy, 'instance', $instance->id, $instance->node_id, [], $triggeredBy, $requestId);
    }
}
