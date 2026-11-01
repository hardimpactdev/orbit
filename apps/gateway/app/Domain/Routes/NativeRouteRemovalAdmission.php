<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Records a complete withdrawal inventory before the native root starts. */
final readonly class NativeRouteRemovalAdmission
{
    public function __construct(private NativeRouteRemovalIntent $intent, private StartOperationAction $operations) {}

    public function start(string $kind, Route $route, bool $offline = false, ?Node $caller = null, ?string $requestId = null): Operation
    {
        return DB::transaction(fn (): Operation => $this->startOwned($kind, $route, $offline, $caller, $requestId));
    }

    private function startOwned(string $kind, Route $route, bool $offline, ?Node $caller, ?string $requestId): Operation
    {
        $snapshot = $this->intent->capture($route, ['offline' => $offline]);
        $nodeId = $snapshot['nodes'][0]['node_id'] ?? null;
        if ($offline || $nodeId === null) {
            $nodeId = Node::query()->where('status', LifecycleStatus::Active)
                ->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))
                ->orderBy('id')->value('id');
        }
        if (! is_int($nodeId)) {
            throw new ResourceOperationException('route.coordinator_required', 'Route withdrawal requires an admitted coordinator.', 409);
        }
        $nodes = array_values(array_unique([$nodeId, ...array_column($snapshot['nodes'], 'node_id'), ...ValidatedData::integerList($snapshot['dns_node_ids'])]));
        sort($nodes);
        $this->intent->beginWithdrawal($route);

        $operation = $this->operations->execute($kind, 'route', $route->id, $nodeId, [
            'route_id' => $route->id, 'route_removal' => $snapshot, 'offline' => $offline,
            'node_ids' => $nodes, 'participant_node_ids' => $nodes,
            'access_node_ids' => $nodes, 'caller_node_id' => $caller?->id,
        ], 'operator', $requestId);
        if ($operation->wasRecentlyCreated) {
            $writes = $secrets = [];
            $captureId = bin2hex(random_bytes(8));
            foreach ($nodes as $owner) {
                foreach (app(NativeRouteEnvironmentMaterial::class)->forNode($operation, $owner) as $write) {
                    $field = 'route_environment_'.count($writes);
                    $private = array_intersect_key($write['input'], array_flip(['content', 'app_key', 'app_name']));
                    $secrets[$field] = app(SwampCommandStep::class)->executionCopy('orbit-control-inputs', 'route-removal-'.$captureId.'-'.$field, json_encode($private, JSON_THROW_ON_ERROR));
                    $writes[] = ['control_node_id' => 209, ...app(NativeCaddyPublicationInput::class)->connectionForNode(Node::query()->findOrFail($owner)),
                        'write' => ['index' => count($writes), 'route_id' => $route->id, 'node_id' => $owner,
                            'instance_id' => $write['instance_id'], 'name' => $write['name'],
                            'input' => array_diff_key($write['input'], $private), 'secret_field' => $field]];
                }
            }
            $operation->update(['input' => [...$operation->input, 'route_environment' => $writes, 'execution_secrets' => $secrets]]);
        }

        return $operation;
    }
}
