<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationKinds;
use App\Models\Instance;
use App\Models\Operation;

/**
 * Starts the `instance.rollback` operation (docs/reference/deployments.md#the-rollback-operation). It refuses an Instance that cannot
 * be rolled back before it records anything; the job then runs the rollback on the Gateway queue. A rollback always
 * wins over a deploy that waits in the queue: that deploy ends `cancelled` with `operation.superseded`. A deploy that a
 * worker started keeps the Instance, and the rollback is busy.
 */
final readonly class StartInstanceRollbackAction
{
    public function __construct(
        private RollbackInstanceAction $rollback,
        private StartOperationAction $operations,
    ) {}

    public function execute(Instance $instance, string $release, string $triggeredBy, ?string $requestId): Operation
    {
        $this->operations->assertAvailable();
        $this->rollback->plan($instance);

        return $this->operations->execute(
            OperationKinds::InstanceRollback,
            OperationKinds::InstanceTarget,
            $instance->id,
            $instance->node_id,
            ['release' => $release],
            $triggeredBy,
            $requestId,
            supersedes: [OperationKinds::InstanceDeploy],
        );
    }
}
