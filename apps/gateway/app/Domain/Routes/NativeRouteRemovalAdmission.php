<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSite;
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
            'route_id' => $route->id, 'route_removal' => $snapshot, 'offline' => $offline, // A private Route without a Process or an Ingress placement: a Node-local proxy to a loopback upstream is one too.
            'route_private_development' => $route->publication === RoutePublication::Private && $route->cluster?->ingressAssignment === null
                && ($route->customProxy?->process_id === null),
            'route_guards' => $offline || ! RouteFileGuards::wanted($kind) ? [] : app(RouteFileGuards::class)->capture(self::guarded(ValidatedData::object($capture['intent']['plan'])),
                app(DevelopmentSiteRepository::class)->all()->reject(static fn (DevelopmentSite $site): bool => $site->domain === $route->domain)->values()),
            'route_lifecycle' => $capture['intent'], 'certificates' => $capture['certificates'], 'execution_secrets' => $capture['execution_secrets'],
            'node_ids' => $nodes, 'participant_node_ids' => array_values(array_unique([...$nodes, ...ValidatedData::integerList($capture['participant_node_ids'])])),
            'access_node_ids' => $nodes, 'caller_node_id' => $caller?->id,
        ], 'operator', $requestId);
    }

    /**
     * The Nodes whose files the engine's withdrawal replaces: each Node that leaves the Route and each VPN Node.
     *
     * @param  array<string, mixed>  $plan
     * @return list<int>
     */
    private static function guarded(array $plan): array
    {
        return [...ValidatedData::integerList($plan['retire_nodes']), ...array_map(static fn (array $row): int => ValidatedData::integer($row['node_id']), RouteExecutionProof::objects($plan['dns']))];
    }
}
