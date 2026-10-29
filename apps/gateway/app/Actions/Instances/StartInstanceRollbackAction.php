<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\Deployment\InstanceDeploymentAvailability;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\Deployment\ProductionDeployment;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;

/**
 * Starts the `instance.rollback` operation (docs/reference/deployments.md#the-rollback-operation). It refuses an
 * Instance that cannot be rolled back, or a release it does not retain, before it records anything; Swamp then executes
 * the rollback from the recorded intent.
 * A rollback wins over a deploy that no worker has started: that deploy ends `cancelled` with `operation.superseded`.
 * A deploy that a worker started keeps the Instance, also while it waits to resume, and the rollback is busy.
 */
final readonly class StartInstanceRollbackAction
{
    public function __construct(
        private InstanceDeploymentIntent $intent,
        private ProductionDeployment $releases,
        private StartOperationAction $operations,
    ) {}

    public function execute(Instance $instance, string $release, string $triggeredBy, ?string $requestId): Operation
    {
        $deployment = $this->intent->snapshot($instance, rollback: true);
        InstanceDeploymentAvailability::assertAvailable($deployment);
        // A release the Instance does not retain must not cancel a queued deploy, so it is refused first.
        if (! in_array($release, $this->releases->releases($instance)->releases, true)) {
            throw new ResourceOperationException('rollback.release_not_retained', "The Instance does not retain the release [{$release}]. List its releases with instance:release:list.", 422, details: ['release' => $release]);
        }

        return $this->operations->execute(
            OperationKinds::InstanceRollback,
            OperationKinds::InstanceTarget,
            $instance->id,
            $instance->node_id,
            ['instance_id' => $instance->id, 'release' => $release, 'deployment' => $deployment],
            $triggeredBy,
            $requestId,
            supersedes: [OperationKinds::InstanceDeploy],
        );
    }
}
