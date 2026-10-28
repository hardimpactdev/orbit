<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Actions\Operations\StartOperationAction;
use App\Data\Routes\CreateRouteData;
use App\Data\Routes\SetRouteTargetsData;
use App\Data\Routes\UpdateRouteData;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\ReservedPrivateHostname;
use App\Domain\Routes\RouteAssociationGuard;
use App\Domain\Routes\RouteDomain;
use App\Domain\Routes\RouteExecutionProof;
use App\Domain\Routes\RouteKitAvailability;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RouteReconciliationGuard;
use App\Domain\Routes\RouteStatus;
use App\Domain\Routes\RouteTargetSetGuard;
use App\Domain\Routes\RouteWebRoot;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Shared\StoredInteger;
use App\Domain\WebSocket\WebSocketRouteSelection;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Normal API admission. Route intent and its Operation commit together. */
final readonly class AdmitRouteOperationAction
{
    public function __construct(private StartOperationAction $operations, private CreateRouteAction $create,
        private RouteMutationState $mutations, private RouteTargetState $targets, private RouteTargetSetGuard $targetGuard,
        private RouteRemovalState $removal, private RouteAssociationGuard $associations,
        private RouteReconciliationGuard $reconciliation, private ReservedPrivateHostname $reserved) {}

    public function create(CreateRouteData $data, ?Node $caller = null, ?string $requestId = null): Operation
    {
        RouteKitAvailability::kind('route.create');

        return DB::transaction(function () use ($data, $caller, $requestId): Operation {
            $result = $this->create->executeForRouteCreate($data);
            $route = $result['route'];

            return $this->start('route.create', $route, ['mode' => 'converge', 'created' => $result['created'],
                'instance_id' => $data->instanceId], $caller, $requestId);
        });
    }

    public function update(Route $route, UpdateRouteData $data, ?Node $caller = null, ?string $requestId = null, bool $allowGenerated = false): Operation
    {
        RouteKitAvailability::kind('route.update');

        return DB::transaction(function () use ($route, $data, $caller, $requestId, $allowGenerated): Operation {
            $route = Route::query()->with('targets.instance.node')->lockForUpdate()->findOrFail($route->id);
            if (! $route->isApp()) {
                throw new ResourceOperationException('route.kind_unsupported', 'Only a Project Route can change domain or publication.', 409);
            }
            if ($data->webRootProvided && ($data->domainProvided || $data->publicationProvided)) {
                throw new ResourceOperationException('route.web_root_update_separate', 'Send a Route web root change on its own.', 422);
            }
            $domain = $data->domainProvided && $data->domain !== null ? RouteDomain::validate($data->domain) : $route->domain;
            $changed = $domain !== $route->domain || ($data->publicationProvided && $data->publication !== $route->publication)
                || ($data->webRootProvided && $data->webRoot !== $route->web_root);
            if ($changed) {
                WebSocketRouteSelection::assertMutable($route);
            }
            $input = ['mode' => $data->webRootProvided ? 'web_root' : 'publication',
                'domain' => $domain, 'publication' => ($data->publication ?? $route->publication)->value,
                'web_root' => $data->webRootProvided ? RouteWebRoot::normalize($data->webRoot) : $route->web_root,
                'previous_web_root' => $route->web_root, 'previous_publication' => $route->publication->value,
                'allow_generated' => $allowGenerated];
            if ($data->webRootProvided) {
                app(RouteWebRootState::class)->assertChange($route, $input['web_root']);
            }
            if ($data->domainProvided && ((! $allowGenerated && $route->provenance !== RouteProvenance::Explicit) || $data->domain === null)) {
                throw new ResourceOperationException('route.domain_immutable', 'Only an explicit Route domain can be updated.', 409);
            }
            if ($domain !== $route->domain || $route->replaced_by_route_id !== null) {
                $this->reserved->assertAvailable($domain);
                if ($route->hasWebRoot()) {
                    throw new ResourceOperationException('route.web_root_domain_immutable', 'A Route with a web root keeps its domain.', 409);
                }
                if ($route->provenance !== RouteProvenance::Explicit && ! $allowGenerated) {
                    throw new ResourceOperationException('route.domain_immutable', 'Only an explicit Route domain can be updated.', 409);
                }
                if ($route->status !== RouteStatus::Pending) {
                    $this->mutations->eligibleTargets($route, allowRetiring: $route->status === RouteStatus::Retiring,
                        publication: $data->publication, allowGenerated: $allowGenerated);
                } else {
                    $this->reconciliation->assertRouteMutable($route);
                }
                $candidate = $this->mutations->reserve($route, $domain, $data->publication);
                $input['candidate_route_id'] = $candidate->id;
                $input['mode'] = $route->status === RouteStatus::Pending ? 'replace_pending' : 'replace_domain';
            }

            return $this->start('route.update', $route, $input, $caller, $requestId);
        });
    }

    public function setTargets(Route $route, SetRouteTargetsData $proposal, ?Node $caller = null, ?string $requestId = null): Operation
    {
        RouteKitAvailability::kind('route.target_set');

        return DB::transaction(function () use ($route, $proposal, $caller, $requestId): Operation {
            $route = Route::query()->with('targets')->lockForUpdate()->findOrFail($route->id);
            $this->targetGuard->assertCompatibleIntent($route, $proposal);
            $this->targetGuard->assertProposal($route, $proposal);
            WebSocketRouteSelection::assertMutable($route);
            $this->targets->reserve($route, $proposal);

            return $this->start('route.target_set', $route, ['mode' => 'targets',
                'targets' => $proposal->targetIds, 'dispositions' => $proposal->toIntent()['dispositions']], $caller, $requestId);
        });
    }

    public function setTarget(Route $route, int $instanceId, ?Node $caller = null, ?string $requestId = null): Operation
    {
        RouteKitAvailability::kind('route.target_set');

        return DB::transaction(function () use ($route, $instanceId, $caller, $requestId): Operation {
            $previous = $route->targets()->pluck('instance_id')->map(StoredInteger::from(...))->values()->all();
            $updated = app(SetRouteTargetAction::class)->execute($route, $instanceId);

            return $this->start('route.target_set', $updated, ['mode' => 'target', 'instance_id' => $instanceId, 'previous_target_instance_ids' => $previous], $caller, $requestId);
        });
    }

    public function clearTarget(Route $route, ?Node $caller = null, ?string $requestId = null): Operation
    {
        RouteKitAvailability::kind('route.target_unset');

        return DB::transaction(function () use ($route, $caller, $requestId): Operation {
            $previous = $route->targets()->pluck('instance_id')->map(StoredInteger::from(...))->values()->all();
            $updated = app(ClearRouteTargetAction::class)->execute($route);

            return $this->start('route.target_unset', $updated, ['mode' => 'state_only', 'previous_target_instance_ids' => $previous], $caller, $requestId);
        });
    }

    public function destroy(Route $route, bool $offline = false, ?Node $caller = null, ?string $requestId = null): Operation
    {
        RouteKitAvailability::kind('route.destroy');

        return DB::transaction(function () use ($route, $offline, $caller, $requestId): Operation {
            $ids = array_values($route->targets()->pluck('instance_id')->map(StoredInteger::from(...))->all());
            $route = $this->removal->lockAndGuard($route, $ids);
            if (! $route->isUnactivatedReplacement()) {
                $this->removal->assertStandaloneRemovalAllowed($route, false);
                if (! $route->hasWebRoot()) {
                    $this->associations->assertTargetsDetachable($route);
                    $this->reconciliation->assertRouteMutable($route);
                }
            }

            return $this->start('route.destroy', $route, ['mode' => 'destroy', 'offline' => $offline], $caller, $requestId);
        });
    }

    /** @param array<string, mixed> $input */
    public function start(string $kind, Route $route, array $input, ?Node $caller = null, ?string $requestId = null): Operation
    {
        RouteKitAvailability::kind($kind);
        $route->loadMissing(['targets.instance.node', 'cluster.routerAssignment.node', 'cluster.ingressAssignment.node', 'customProxy.node']);
        $currentIds = $route->targets->pluck('instance_id')->map(StoredInteger::from(...))->values()->all();
        $proposedIds = $this->instanceIds($input['targets'] ?? $currentIds);
        $previousIds = $this->instanceIds($input['previous_target_instance_ids'] ?? $currentIds);
        $removedIds = array_values(array_map(static fn (array $disposition): int => StoredInteger::from($disposition['instance_id']),
            array_filter(RouteExecutionProof::objects($input['dispositions'] ?? []), static fn (array $disposition): bool => ($disposition['remove'] ?? false) === true)));
        $placements = Instance::query()->whereKey(array_values(array_unique([...$proposedIds, ...$previousIds, ...$removedIds])))->orderBy('id')->get()
            ->map(static fn (Instance $instance): array => ['instance_id' => $instance->id, 'node_id' => $instance->node_id, 'production' => $instance->placedOnAppProd()])->values()->all();
        $workloads = array_values(array_unique(array_column($placements, 'node_id')));
        $routerId = $route->cluster?->routerAssignment?->node_id;
        $proxyId = $route->customProxy?->node_id;
        $nodeId = $workloads[0] ?? $route->node_id ?? $routerId ?? $proxyId;
        if (! is_int($nodeId)) {
            throw new ResourceOperationException('route.router_required', 'The Route requires a serving Node.', 409);
        }
        $nodes = array_values(array_unique(array_filter([...$workloads, $nodeId, $route->cluster?->routerAssignment?->node_id,
            $route->cluster?->ingressAssignment?->node_id,
            ...Node::query()->whereHas('roles', static fn ($q) => $q->whereIn('role', [RoleName::Vpn, RoleName::Gateway]))->pluck('id')->all()], is_int(...))));

        $accessNodes = [$nodeId];
        if ($route->isCustomProxy() && $route->cluster_id !== null) {
            $proxy = $route->customProxy;
            $ingress = $route->cluster?->ingressAssignment;
            if ($proxy === null || $ingress === null) {
                throw new ResourceOperationException('route.ingress_required', 'The public Process Route requires its stored Process and Ingress.', 409);
            }
            $accessNodes = [$proxy->node_id, $ingress->node_id];
        }

        return $this->operations->execute($kind, 'route', $route->id, $nodeId,
            ['route_id' => $route->id, 'node_ids' => $nodes,
                'public_process' => $route->isCustomProxy() && $route->cluster_id !== null,
                'access_node_ids' => $accessNodes,
                'caller_node_id' => $caller?->id, ...$input, 'target_instance_ids' => $proposedIds,
                'previous_target_instance_ids' => $previousIds, 'removed_instance_ids' => $removedIds, 'instance_placements' => $placements], 'operator', $requestId);
    }

    /** @return list<int> */
    private function instanceIds(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new ResourceOperationException('route.target_conflict', 'Route intent requires an ordered Instance list.', 409);
        }

        return array_map(ValidatedData::integer(...), $value);
    }
}
