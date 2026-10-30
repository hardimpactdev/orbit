<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;

final readonly class StartInstanceProjectionRepairAction
{
    public function __construct(private InstanceDeploymentIntent $intents, private StartOperationAction $operations) {}

    public function execute(Instance $instance): Operation
    {
        $this->intents->assertDevelopment($instance->refresh());
        $route = $instance->authoritativeRoute();
        if (! $instance->development_projection_pending || $route === null) {
            throw new ResourceOperationException('instance.projection_unavailable', 'This Instance has no pending Route projection.', 409);
        }

        return $this->operations->execute('instance.repair_projection', 'instance', $instance->id, $instance->node_id,
            ['instance_id' => $instance->id, 'route_id' => $route->id], 'orbit:projection-repair', null);
    }
}
