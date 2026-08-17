<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles;

use App\Enums\Apps\InstanceDriver;
use App\Enums\Nodes\NodeRoleName;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
use App\Models\ProxyRoute;
use App\Services\Proxy\InstanceProxyRouteOwnershipResolver;
use App\Services\Proxy\PublicBindingProxyRouteOwnership;
use App\Services\Proxy\WorkspaceProxyRouteOwnershipResolver;
use App\Services\S3\S3RouteRegistrar;
use App\Services\Workspaces\WorkspacePlacement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** @mago-expect lint:kan-defect */
class NodeRoleDependencyInspector
{
    /**
     * @var array<string, string>
     */
    private const array AppRoleEnvironments = [
        NodeRoleName::AppDevelopment->value => 'development',
        NodeRoleName::AppProduction->value => 'production',
    ];

    /**
     * @var list<string>
     */
    private const array DatabaseProcessServices = [
        'mysql',
        'postgres',
        'valkey',
        'clickhouse',
    ];

    /**
     * @return list<string>
     */
    public function dependentSummaries(Node $node, NodeRoleAssignment $assignment): array
    {
        if (array_key_exists($assignment->role, self::AppRoleEnvironments)) {
            return $this->appRoleDependentSummaries($node, $assignment->role);
        }

        if ($assignment->role === NodeRoleName::Database->value) {
            return $this->databaseDependentSummaries($node);
        }

        if ($assignment->role === NodeRoleName::Ingress->value) {
            return $this->ingressDependentSummaries($node);
        }

        return [];
    }

    /**
     * @param  list<int>  $ingressRouteIds
     */
    public function removeOrbitOwnedDependents(
        Node $node,
        NodeRoleAssignment $assignment,
        array $ingressRouteIds = [],
    ): void {
        if (array_key_exists($assignment->role, self::AppRoleEnvironments)) {
            $this->removeAppRoleDependents($node, $assignment->role);

            return;
        }

        if ($assignment->role === NodeRoleName::Database->value) {
            $this->removeDatabaseDependents($node);

            return;
        }

        if ($assignment->role === NodeRoleName::Ingress->value) {
            $this->removeIngressDependents($node, $ingressRouteIds);
        }
    }

    /**
     * @return list<string>
     */
    private function appRoleDependentSummaries(Node $node, string $role): array
    {
        $environment = self::AppRoleEnvironments[$role];
        // Dependents are the concrete Orbit instances placed on this node that
        // resolve to the role's environment; count the distinct owning apps.
        $count = count(array_unique(array_map(
            static fn (Instance $instance): int => (int) $instance->app_id,
            $this->appRoleDependentInstances($node, $environment),
        )));

        if ($count === 0) {
            return [];
        }

        return ["{$count} {$environment} app ".($count === 1 ? 'record' : 'records')];
    }

    /**
     * Orbit instances placed on the node that resolve to the given environment.
     *
     * @return list<Instance>
     */
    private function appRoleDependentInstances(Node $node, string $environment): array
    {
        $placement = app(WorkspacePlacement::class);

        /** @var list<Instance> $instances */
        $instances = Instance::query()
            ->where('driver', InstanceDriver::Orbit->value)
            ->where('driver_config->data->node_id', $node->id)
            ->with('app')
            ->get()
            ->filter(
                static fn (Instance $instance): bool => (
                    $instance->app instanceof App
                    && $placement->runtimeEnvironment($instance->app, $instance) === $environment
                ),
            )
            ->values()
            ->all();

        return $instances;
    }

    /**
     * @return list<string>
     */
    private function databaseDependentSummaries(Node $node): array
    {
        $count = Process::query()
            ->ownedBy($node)
            ->withRuntimeService(self::DatabaseProcessServices)
            ->count();

        if ($count === 0) {
            return [];
        }

        return ["{$count} database process ".($count === 1 ? 'record' : 'records')];
    }

    private function removeAppRoleDependents(Node $node, string $role): void
    {
        $environment = self::AppRoleEnvironments[$role];

        DB::transaction(function () use ($node, $environment): void {
            $instances = $this->appRoleDependentInstances($node, $environment);

            if ($instances === []) {
                return;
            }

            $instanceIds = array_map(static fn (Instance $instance): int => (int) $instance->id, $instances);
            $appIds = array_values(array_unique(array_map(
                static fn (Instance $instance): int => (int) $instance->app_id,
                $instances,
            )));

            // Remove the node-bound instances and their routes served on this
            // node; the logical App and any instances on other nodes survive.
            $instanceRouteOwnership = new InstanceProxyRouteOwnershipResolver;
            $workspaceRouteOwnership = new WorkspaceProxyRouteOwnershipResolver;

            $routeIds = [];

            foreach ($instances as $candidateInstance) {
                foreach ($candidateInstance->proxyRoutes()->get() as $route) {
                    assert($route instanceof ProxyRoute, 'Instance proxy route relation returns ProxyRoute models.');

                    $instance = $route->owner_type === 'workspace'
                        ? $workspaceRouteOwnership->resolve($route)?->instance
                        : $instanceRouteOwnership->resolve($route);

                    if ($instance instanceof Instance && in_array($instance->id, $instanceIds, true)) {
                        $routeIds[] = $route->id;
                    }
                }
            }

            if ($routeIds !== []) {
                ProxyRoute::query()->whereIn('id', $routeIds)->delete();
            }

            Instance::query()
                ->whereIn('id', $instanceIds)
                ->delete();

            // Only delete an App once it has no remaining concrete instances.
            foreach ($appIds as $appId) {
                if (Instance::query()->where('app_id', $appId)->doesntExist()) {
                    App::query()->whereKey($appId)->delete();
                }
            }
        });
    }

    private function removeDatabaseDependents(Node $node): void
    {
        Process::query()
            ->ownedBy($node)
            ->withRuntimeService(self::DatabaseProcessServices)
            ->delete();
    }

    /**
     * @return list<string>
     */
    private function ingressDependentSummaries(Node $node): array
    {
        $count = count($this->ingressDependentRouteIds($node));

        if ($count === 0) {
            return [];
        }

        return ["{$count} public proxy route ".($count === 1 ? 'record' : 'records')];
    }

    /**
     * @param  list<int>  $capturedRouteIds
     */
    private function removeIngressDependents(Node $node, array $capturedRouteIds): void
    {
        $routeIds = array_values(array_unique([
            ...$capturedRouteIds,
            ...$this->ingressDependentRouteIds($node),
        ]));

        if ($routeIds === []) {
            return;
        }

        ProxyRoute::query()->whereIn('id', $routeIds)->delete();
    }

    /**
     * Valid public routes served by this ingress node. Primary App and
     * Workspace routes also require production placement through their
     * concrete Instance because App owns no environment column.
     *
     * @return list<int>
     */
    public function ingressDependentRouteIds(Node $node): array
    {
        $placement = app(WorkspacePlacement::class);
        $instanceRouteOwnership = new InstanceProxyRouteOwnershipResolver;
        $workspaceRouteOwnership = new WorkspaceProxyRouteOwnershipResolver;

        $ingressRouteIds = ProxyRoute::query()
            ->where('node_id', $node->id)
            ->where('config->placement', 'ingress')
            ->where(function (Builder $query): void {
                $query
                    ->where(function (Builder $query): void {
                        $query
                            ->where('owner_type', 'app')
                            ->where('kind', 'app');
                    })
                    ->orWhere(function (Builder $query): void {
                        $query
                            ->where('owner_type', 'workspace')
                            ->where('kind', 'workspace');
                    })
                    ->orWhereIn('owner_type', ['app-analytics', 'app-websocket', 's3']);
            })
            ->with(['instance.app', 'workspace'])
            ->get()
            ->filter(static function (ProxyRoute $route) use (
                $instanceRouteOwnership,
                $placement,
                $workspaceRouteOwnership,
            ): bool {
                if ($route->owner_type === 'workspace') {
                    $ownership = $workspaceRouteOwnership->resolve($route);

                    if ($ownership === null) {
                        return false;
                    }

                    return $placement->runtimeEnvironment($ownership->app, $ownership->instance) === 'production';
                }

                if (InstanceProxyRouteOwnershipResolver::isPublicBindingOwner($route->owner_type)) {
                    return app(PublicBindingProxyRouteOwnership::class)->matches($route);
                }

                if ($route->owner_type === 's3') {
                    return app(S3RouteRegistrar::class)->ownsPublicRoute($route, $route->domain);
                }

                $instance = $instanceRouteOwnership->resolve($route);

                return (
                    $instance instanceof Instance
                    && $placement->runtimeEnvironment($instance->app, $instance) === 'production'
                );
            })
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values();

        /** @var list<int> $routeIds */
        $routeIds = $ingressRouteIds->all();

        return $routeIds;
    }
}
