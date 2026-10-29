<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Routes\RouteKitAvailability;
use App\Domain\Routes\RouteParentCommand;
use App\Domain\Routes\RouteParentExecution;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Shared\StoredInteger;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** The composition boundary used by Instance lifecycle Operations. No Node work. */
final readonly class StartRouteProjectionAction
{
    public function __construct(private StartOperationAction $operations) {}

    public function converge(Route $route, Instance $instance, ?string $requestId = null): Operation
    {
        RouteKitAvailability::kind('route.converge');
        if (! $route->targets()->where('instance_id', $instance->id)->exists()) {
            throw new ResourceOperationException('route.target_conflict', 'The Instance must be a recorded Route target.', 409);
        }

        return $this->operation('route.converge', 'route', $route->id, $instance->node_id,
            ['route_id' => $route->id, 'instance_id' => $instance->id], 'operator', $requestId);
    }

    public function withdraw(Route $route, bool $offline = false, ?string $requestId = null): Operation
    {
        RouteKitAvailability::kind('route.withdraw');
        $nodeId = $route->node_id ?? $route->cluster?->routerAssignment?->node_id;
        if (! is_int($nodeId)) {
            throw new ResourceOperationException('route.router_required', 'The Route has no serving Node.', 409);
        }

        return $this->operation('route.withdraw', 'route', $route->id, $nodeId,
            ['route_id' => $route->id, 'offline' => $offline], 'operator', $requestId);
    }

    public function withdrawInstance(Instance $instance, ?string $requestId = null): Operation
    {
        return $this->operation('route.withdraw_instance', 'instance_routes', $instance->id, $instance->node_id,
            ['instance_id' => $instance->id], 'operator', $requestId);
    }

    public function cleanupInstance(Instance $instance, ?string $requestId = null): Operation
    {
        return $this->operation('route.cleanup_instance', 'instance_routes', $instance->id, $instance->node_id,
            ['instance_id' => $instance->id], 'operator', $requestId);
    }

    public function retireSource(InstanceTransfer $transfer, ?string $requestId = null): Operation
    {
        RouteKitAvailability::kind('route.retire_source');
        if ($transfer->cutover_at === null || $transfer->source_router_node_id === null) {
            throw new ResourceOperationException('instance.transfer_source_router_unknown', 'Record cutover and the source Router before retiring source projections.', 409);
        }

        return $this->operation('route.retire_source', 'route', $transfer->destination_route_id ?? $transfer->source_route_id,
            $transfer->source_node_id, ['route_id' => $transfer->destination_route_id ?? $transfer->source_route_id,
                'transfer_id' => $transfer->id], 'operator', $requestId);
    }

    /** @param array<string, mixed> $input */
    private function operation(string $kind, string $targetType, int $targetId, int $nodeId, array $input, string $actor, ?string $requestId): Operation
    {
        $ids = isset($input['instance_id']) ? [$input['instance_id']] : Route::query()->find($targetId)?->targets()->pluck('instance_id')->map(StoredInteger::from(...))->values()->all() ?? [];

        return app(InstanceEnvironmentOperationLock::class)->run(array_map(ValidatedData::integer(...), array_values($ids)), fn (): Operation => DB::transaction(function () use ($kind, $targetType, $targetId, $nodeId, $input, $actor, $requestId): Operation {
            $operation = $this->operations->execute($kind, $targetType, $targetId, $nodeId, $input, $actor, $requestId);
            if (in_array($kind, RouteParentExecution::Kinds, true)) {
                app(RouteParentCommand::class)->run($operation, ['action' => 'prepare']);
            }

            return $operation->refresh();
        }));
    }
}
