<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
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
        // No rollback: the run never restores, so no previous material is staged.
        $capture = app(NativeRouteLifecycleInput::class)->capture($route, ['offline' => $offline], true, restorable: false);

        return $this->operations->execute($kind, 'route', $route->id, $nodeId, [
            'route_id' => $route->id, 'route_removal' => $snapshot, 'offline' => $offline, 'route_guards' => $offline || ! in_array($kind, (array) config('orbit.engine.kinds'), true) ? [] : $this->guards(ValidatedData::object($capture['intent']['plan'])),
            'route_lifecycle' => $capture['intent'], 'certificates' => $capture['certificates'], 'execution_secrets' => $capture['execution_secrets'],
            'node_ids' => $nodes, 'participant_node_ids' => array_values(array_unique([...$nodes, ...ValidatedData::integerList($capture['participant_node_ids'])])),
            'access_node_ids' => $nodes, 'caller_node_id' => $caller?->id,
        ], 'operator', $requestId);
    }

    /**
     * The digest of each whole file the withdrawal replaces, rendered from the sites as they are now: what the engine's guarded
     * steps require on the Node (RouteDestroyEngineFlow). The plan's own publication states keep only the changed blocks.
     *
     * @param  array<string, mixed>  $plan
     * @return array<int, array<string, string>>
     */
    private function guards(array $plan): array
    {
        $sites = app(DevelopmentSiteRepository::class)->all();
        $guards = [];
        $nodes = [...ValidatedData::integerList($plan['retire_nodes']), ...array_map(static fn (array $row): int => ValidatedData::integer($row['node_id']), RouteExecutionProof::objects($plan['dns']))];
        foreach (array_values(array_unique($nodes)) as $id) {
            $node = Node::query()->find($id);
            if ($node === null) {
                continue;
            }
            $before = app(NativeRoutePublicationInput::class)->snapshot($node, $sites, true);
            $caddy = ValidatedData::object($before['caddy'])['content'] ?? null;
            $dns = is_array($before['dns']) ? $before['dns'] : null;
            $guards[$id] = array_filter(['caddy' => is_string($caddy) ? hash('sha256', $caddy) : null,
                'records' => $dns === null ? null : hash('sha256', ValidatedData::string($dns['records'])),
                'catalog' => $dns === null ? null : hash('sha256', ValidatedData::string($dns['catalog']))], is_string(...));
        }

        return $guards;
    }
}
