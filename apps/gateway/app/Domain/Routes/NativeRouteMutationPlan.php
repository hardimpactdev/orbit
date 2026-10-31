<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Actions\Routes\RouteMutationState;
use App\Domain\Analytics\AnalyticsRoutePlan;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WebSocket\WebSocketRouteSelection;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Resource capture and state changes reused inside an owning Cluster or Node root. */
final readonly class NativeRouteMutationPlan
{
    public function __construct(private RouteMutationState $state, private NativeRouteConvergenceIntent $convergence) {}

    /** The caller owns admission and supplies the reconciler's validated proposals.
     * @param  list<array{route:Route,domain:string,placement?:RoutePlacement}>  $changes
     * @return array<string,mixed>
     */
    public function capture(array $changes): array
    {
        return DB::transaction(function () use ($changes): array {
            $rows = [];
            $nodes = [];
            $certificates = [];
            $seen = [];
            foreach ($changes as $change) {
                $route = Route::query()->with('targets.instance.node')->lockForUpdate()->findOrFail($change['route']->id);
                if (isset($seen[$route->id]) || ! $route->isApp() || $route->publication !== RoutePublication::Private
                    || $route->replaced_by_route_id !== null || $route->replaces_route_id !== null || $route->hasPlacementTransition()
                    || $route->replacement_step !== null) {
                    throw $this->conflict();
                }
                $seen[$route->id] = true;
                $domain = RouteDomain::validate($change['domain']);
                WebSocketRouteSelection::assertMutable($route);
                $this->state->eligibleTargets($route, allowGenerated: $route->provenance === RouteProvenance::Generated);
                $placement = $change['placement'] ?? new RoutePlacement($route->node_id, $route->cluster_id, null);
                if (($placement->nodeId === null) === ($placement->clusterId === null)) {
                    throw $this->conflict();
                }
                if ($route->domain === $domain && $route->node_id === $placement->nodeId && $route->cluster_id === $placement->clusterId) {
                    continue;
                }
                $previous = $this->convergence->forRoute($route, ['mode' => 'placement']);
                $before = array_intersect_key($route->getAttributes(), array_flip(['node_id', 'cluster_id', 'status', 'sites_published', 'replacement_step', 'failed_step', 'error_code']));
                $candidate = $domain === $route->domain ? $this->state->candidateWithPlacement($route, $placement)
                    : $this->state->reserve($route, $domain, placement: $placement);
                $desired = $this->convergence->forRoute($candidate, ['mode' => 'placement']);
                if ($candidate->id === $route->id) {
                    $members = RouteExecutionProof::objects($desired['workloads']);
                    $scopes = [];
                    foreach ($members as &$member) {
                        $scope = 'app-instance-'.ValidatedData::integer($member['instance_id']).'-hostname-change';
                        $scopes[ValidatedData::string($member['certificate_scope'])] = $scope;
                        $member['certificate_scope'] = $scope;
                    }
                    unset($member);
                    $desired['workloads'] = $members;
                    $desired['certificates'] = array_map(static function (array $leaf) use ($scopes): array {
                        $scope = ValidatedData::string($leaf['scope']);

                        return [...$leaf, 'scope' => $scopes[$scope] ?? $scope.'-hostname-change'];
                    }, RouteExecutionProof::objects($desired['certificates']));
                }
                $live = clone $candidate;
                $live->replacement_step = RouteReplacementStep::Cleanup;
                $cleanup = $this->convergence->forRoute($live, ['mode' => 'placement']);
                $rowNodes = array_values(array_unique([...ValidatedData::integerList($previous['node_ids']), ...ValidatedData::integerList($desired['node_ids'])]));
                sort($rowNodes);
                $rows[] = ['route_id' => $route->id, 'result_route_id' => $candidate->id, 'before' => $before,
                    'placement' => ['node_id' => $placement->nodeId, 'cluster_id' => $placement->clusterId],
                    'previous' => $previous, 'desired' => $desired, 'cleanup' => $cleanup, 'node_ids' => $rowNodes];
                $nodes = [...$nodes, ...$rowNodes];
                $certificates = [...$certificates, ...RouteExecutionProof::objects($previous['certificates']),
                    ...RouteExecutionProof::objects($desired['certificates']), ...RouteExecutionProof::objects($cleanup['certificates'])];
            }
            $nodes = array_values(array_unique($nodes));
            sort($nodes);

            return ['changes' => $rows, 'node_ids' => $nodes, 'certificates' => array_values(array_unique($certificates, SORT_REGULAR))];
        });
    }

    /** Record only the native terminal outcome, inside the parent's final database transaction.
     * @param  array<string,mixed>  $capture
     * @param  array<string,mixed>  $outcome
     */
    public function applyTerminal(array $capture, array $outcome): void
    {
        if (($outcome['ready'] ?? false) !== true || ! is_bool($outcome['restored'] ?? null)) {
            throw $this->conflict();
        }
        $batch = ValidatedData::object($capture['batch']);
        $stages = $outcome['restored'] ? ['rollback', 'rollback-complete']
            : ['workloads-prepared', 'router-prepared', 'commit', 'dns-published', 'withdraw', 'complete'];
        DB::transaction(function () use ($batch, $stages, $capture, $outcome): void {
            foreach ($stages as $stage) {
                $this->checkpoint($batch, $stage);
            }
            $side = ValidatedData::object($capture[$outcome['restored'] ? 'restore' : 'forward']);
            $migrationNodes = array_values(array_map(static fn (array $row): int => ValidatedData::integer($row['node_id']),
                array_filter(RouteExecutionProof::objects($side['lan']), static fn (array $row): bool => ($row['migrate'] ?? false) === true)));
            app(NativeRouteLanInput::class)->recordMigrations($migrationNodes, ValidatedData::integerList($outcome['migrated_node_ids'] ?? []));
            $analytics = RouteExecutionProof::objects($capture['analytics_changes']);
            foreach ($outcome['restored'] ? ['rollback'] : ['certificates_ready', 'public_verified', 'placement_cleanup', 'complete'] as $stage) {
                app(AnalyticsRoutePlan::class)->checkpointUpdate($analytics, $stage);
            }
        });
    }

    /** The parent passes its immutable stored capture in its projection transaction.
     * @param  array<string,mixed>  $batch
     */
    public function checkpoint(array $batch, string $stage): void
    {
        if (! in_array($stage, ['workloads-prepared', 'router-prepared', 'commit', 'dns-published', 'withdraw', 'complete', 'rollback', 'rollback-complete'], true)) {
            throw $this->conflict();
        }
        DB::transaction(function () use ($batch, $stage): void {
            foreach (RouteExecutionProof::objects($batch['changes']) as $row) {
                $source = Route::query()->lockForUpdate()->find(ValidatedData::integer($row['route_id']));
                $route = Route::query()->lockForUpdate()->find(ValidatedData::integer($row['result_route_id']));
                if ($route === null && $stage === 'rollback-complete' && $source !== null && $source->replaced_by_route_id === null) {
                    continue;
                }
                if ($route === null) {
                    throw $this->conflict();
                }
                $renamed = $row['route_id'] !== $route->id;
                if (in_array($stage, ['workloads-prepared', 'router-prepared'], true)
                    && ($route->replacement_step?->hasReached(RouteReplacementStep::DatabaseCutover) === true || ($renamed && $source === null))) {
                    throw $this->conflict();
                }
                $before = ValidatedData::object($row['before']);
                $placement = ValidatedData::object($row['placement']);
                $complete = $route->status === RouteStatus::Active && $route->replacement_step === null && ! $route->hasPlacementTransition()
                    && ($renamed ? $source === null && $route->replaces_route_id === null
                        : ($route->node_id !== $before['node_id'] || $route->cluster_id !== $before['cluster_id']));
                if ($complete) {
                    if (str_starts_with($stage, 'rollback') || in_array($stage, ['workloads-prepared', 'router-prepared'], true)) {
                        throw $this->conflict();
                    }

                    continue;
                }
                if (str_starts_with($stage, 'rollback') && ($route->replacement_step?->hasReached(RouteReplacementStep::DatabaseCutover) === true
                    || ($renamed && $source?->status === RouteStatus::Retiring)
                    || (! $renamed && ($route->node_id !== $before['node_id'] || $route->cluster_id !== $before['cluster_id'])))) {
                    throw $this->conflict();
                }
                if ($stage === 'workloads-prepared') {
                    if ($route->replacement_step?->hasReached(RouteReplacementStep::WorkloadCertificate) === true) {
                        continue;
                    }
                    $route->update(['sites_published' => true, 'replacement_step' => RouteReplacementStep::WorkloadCertificate,
                        ...($renamed ? [] : ['transition_node_id' => $placement['node_id'], 'transition_cluster_id' => $placement['cluster_id']])]);
                } elseif ($stage === 'router-prepared') {
                    if ($route->replacement_step?->hasReached(RouteReplacementStep::RouterCertificate) === true) {
                        continue;
                    }
                    $route->update(['replacement_step' => RouteReplacementStep::RouterCertificate]);
                } elseif ($stage === 'commit') {
                    if ($route->replacement_step?->hasReached(RouteReplacementStep::DatabaseCutover) === true) {
                        continue;
                    }
                    if ($renamed) {
                        if ($source === null) {
                            throw $this->conflict();
                        }
                        $this->state->cutover($source, $route);
                        $source->update(['replacement_step' => RouteReplacementStep::DatabaseCutover]);
                    } else {
                        $this->state->cutoverPlacement($route, new RoutePlacement(
                            ($placement['node_id'] === null ? null : ValidatedData::integer($placement['node_id'])), ($placement['cluster_id'] === null ? null : ValidatedData::integer($placement['cluster_id'])), null));
                    }
                } elseif ($stage === 'dns-published') {
                    $this->state->recordDnsMoved($route);
                } elseif ($stage === 'withdraw') {
                    $route->update(['replacement_step' => RouteReplacementStep::Cleanup]);
                } elseif ($stage === 'complete') {
                    $route->update(['status' => RouteStatus::Active, 'replaces_route_id' => null, 'replacement_step' => null,
                        'transition_node_id' => null, 'transition_cluster_id' => null, 'transition_dns_moved_at' => null, 'failed_step' => null, 'error_code' => null]);
                    if ($renamed && $source !== null) {
                        $source->update(['replaced_by_route_id' => null]);
                        $source->delete();
                    }
                } elseif ($stage === 'rollback') {
                    $route->update($renamed ? ['sites_published' => false, 'status' => RouteStatus::Failed, 'failed_step' => 'native-mutation', 'error_code' => 'route.mutation_failed']
                        : [...$before, 'transition_node_id' => null, 'transition_cluster_id' => null, 'transition_dns_moved_at' => null]);
                } elseif ($renamed) {
                    $source?->update(['replaced_by_route_id' => null]);
                    $route->delete();
                }
            }
        });
    }

    /** Fresh public participant view. The parent owns the shared participant authority check.
     * @return array<string,mixed>
     */
    public function publicInput(Operation $operation, int $nodeId): array
    {
        if (! in_array($nodeId, NativeRouteConvergenceIntent::participants($operation), true)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Node is not an admitted Route mutation participant.', 403);
        }
        $batch = ValidatedData::object($operation->input['route_mutations']);
        $views = [];
        $sites = app(DevelopmentSiteRepository::class)->forNode(Node::query()->findOrFail($nodeId));
        foreach (RouteExecutionProof::objects($batch['changes']) as $row) {
            if (! in_array($nodeId, ValidatedData::integerList($row['node_ids']), true)) {
                continue;
            }
            $rollback = ($operation->state['native_route_mutations_rollback'] ?? false) === true;
            $side = $rollback ? 'previous' : 'desired';
            $intent = ValidatedData::object($row[$side]);
            $route = Route::query()->findOrFail(ValidatedData::integer($intent['route_id']));
            if (! $rollback && $route->replacement_step === RouteReplacementStep::Cleanup) {
                $intent = ValidatedData::object($row['cleanup']);
            }
            if (! $rollback && $row['route_id'] === $row['result_route_id']) {
                $placement = ValidatedData::object($row['placement']);
                $route = $this->state->candidateWithPlacement($route, new RoutePlacement(
                    ($placement['node_id'] === null ? null : ValidatedData::integer($placement['node_id'])), ($placement['cluster_id'] === null ? null : ValidatedData::integer($placement['cluster_id'])), null));
            }
            $view = $this->convergence->publicInputForRoute($operation, $nodeId, $intent, $route);
            $leaves = $rollback ? RouteExecutionProof::objects(ValidatedData::object($row['desired'])['certificates'])
                : [...RouteExecutionProof::objects(ValidatedData::object($row['previous'])['certificates']), ...RouteExecutionProof::objects(ValidatedData::object($row['desired'])['certificates'])];
            $cleanup = [];
            foreach ($leaves as $leaf) {
                $scope = ValidatedData::string($leaf['scope']);
                if ($leaf['node_id'] === $nodeId && ! $sites->contains(static fn (DevelopmentSite $site): bool => $site->loadsCertificate($scope))) {
                    $cleanup[] = array_intersect_key($leaf, array_flip(['node_id', 'user', 'home', 'scope', 'agent_sha256'])) + ['in_use' => false];
                }
            }
            $workloads = array_map(static fn (array $member): array => ['route_id' => $intent['route_id'], ...$member], RouteExecutionProof::objects($view['workloads']));
            $old = ValidatedData::object($row['previous']);
            $removeRouteId = $rollback ? $row['result_route_id'] : $row['route_id'];
            $removesLan = ($rollback || $route->replacement_step === RouteReplacementStep::Cleanup)
                && array_any(RouteExecutionProof::objects($old['workloads']), static fn (array $member): bool => $member['node_id'] === $nodeId);
            $keep = [];
            foreach ($workloads as $member) {
                if (is_array($member['lan']) && $member['lan']['route_id'] === $removeRouteId) {
                    $keep[] = array_intersect_key($member['lan'], array_flip(['router', 'workload']));
                }
            }
            $views[] = ['route_id' => $row['route_id'], ...$view, 'node_ids' => $row['node_ids'], 'workloads' => $workloads,
                'cleanup_lan' => $removesLan ? [['operation' => 'retire', 'route_id' => $removeRouteId, 'keep' => $keep]] : [],
                'cleanup' => array_values(array_unique($cleanup, SORT_REGULAR))];
        }
        $environmentWorkloads = [];
        foreach ($views as $view) {
            $environmentWorkloads = [...$environmentWorkloads, ...RouteExecutionProof::objects($view['workloads'])];
        }
        $environmentWorkloads = array_values(array_unique($environmentWorkloads, SORT_REGULAR));
        foreach ($views as &$view) {
            $view['environment_workloads'] = $environmentWorkloads;
        }
        unset($view);
        $public = app(NativeRoutePublicationInput::class)->forNode(Node::query()->findOrFail($nodeId));

        return [...$public, 'participant_node_id' => $nodeId, 'publication' => ['node_id' => $nodeId], 'route_mutations' => $views];
    }

    /** Existing private environment renderer; the composing family declares and validates its binding.
     * @return list<array{instance_id:int,name:string,input:array<string,mixed>}>
     */
    public function environmentMaterial(Operation $operation, int $nodeId): array
    {
        $batch = ValidatedData::object($operation->input['route_mutations']);
        $writes = [];
        foreach (RouteExecutionProof::objects($batch['changes']) as $row) {
            $side = ($operation->state['native_route_mutations_rollback'] ?? false) === true ? 'previous' : 'desired';
            $writes = [...$writes, ...app(NativeRouteConvergenceEnvironmentMaterial::class)->forNode($operation, $nodeId, ValidatedData::object($row[$side]))];
        }

        return array_values(array_unique($writes, SORT_REGULAR));
    }

    private function conflict(): ResourceOperationException
    {
        return new ResourceOperationException('route.mutation_conflict', 'The Route mutation must match its admitted private placement change.', 409);
    }
}
