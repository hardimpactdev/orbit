<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Instances\Removal\ProductionInstanceSourceRemoval;
use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Instances\ProductionApplicationPaths;
use App\Models\Instance;
use App\Models\InstanceRemovalMember;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Immutable production removal admission and DB-only Route projection. No Node execution.
 * @phpstan-type ProductionRoute array{id:int,targets:list<int>,node_id:int,nodes:list<int>}
 *
 * @phpstan-import-type Descriptor from \App\Domain\Operations\OperationFamilyExecution
 * @phpstan-import-type ChildIntent from OperationFamilyChildren
 */
final readonly class ProductionRemovalIntent
{
    /** @return array<string,mixed> */
    public function snapshot(Instance $instance, bool $force, bool $detached): array
    {
        $this->identity($instance);
        if (! ProductionPhpRuntimeIdentity::isAbsent($instance)) {
            ProductionPhpRuntimeIdentity::from($instance);
        }
        $record = InstanceRemovalMember::query()->where('instance_id', $instance->id)->whereNull('row_deleted_at')->with('removal')->first();
        if ($instance->status === InstanceState::Removing) {
            $previous = $record === null ? null : Operation::query()->where('kind', 'instance.destroy')->where('target_id', $instance->id)
                ->where('input->removal_id', $record->instance_removal_id)->latest('id')->first();
            if ($record === null || $previous === null || $record->removal->requested_instance_id !== $instance->id
                || $record->removal->force !== $force) {
                throw $this->conflict();
            }
            app(ProductionInstanceSourceRemoval::class)->revalidate($record);
            $input = $previous->input;
            $members = InstanceLifecycleInput::members($input);
            $input['members'] = [[...$members[0], ...$record->toArray()]];
            if ($record->route_cleared_at !== null) {
                $input['production_routes'] = [];
            }
            $input['additional_route_ids'] = [];
            $input['additional_routes'] = [];
            $input['production_detached'] = ($previous->state['production_routes_detached'] ?? false) === true;

            return $input;
        }
        $inventory = app(ProductionInstanceSourceRemoval::class)->inventory($instance);
        if ($instance->requiresRoute() && $instance->routes->isEmpty() && ! $detached) {
            throw $this->conflict();
        }
        $primary = $instance->routes->reject(static fn (Route $route): bool => $route->hasWebRoot() || $route->isUnactivatedReplacement())->values();
        if ($primary->count() > 1) {
            throw $this->conflict();
        }
        $routeId = $primary->first()?->id;
        $routes = [];
        $additional = [];
        foreach ($instance->routes as $route) {
            if ($route->project_id !== $instance->project_id || ! $route->targets()->where('instance_id', $instance->id)->exists()) {
                throw $this->conflict();
            }
            if ($route->id === $routeId) {
                $routes[] = $this->route($route, $instance->node_id);
            } else {
                if ($route->targets()->count() !== 1) {
                    throw $this->conflict();
                }
                $additional[] = $route->id;
            }
        }
        $member = ['position' => 0, 'instance_id' => $instance->id, 'project_id' => $instance->project_id, 'node_id' => $instance->node_id,
            'route_id' => $routeId, 'name' => $instance->name, 'environment' => 'production', 'source_layout' => $inventory->layout,
            'repository_identity' => $inventory->repositoryIdentity, 'checkout_path' => $inventory->checkoutPath, 'root' => $inventory->root,
            'branch' => $inventory->branch, 'starting_commit' => $inventory->startingCommit, 'source_commit' => $inventory->startingCommit,
            'common_repository_path' => $inventory->commonRepositoryPath, 'source_identity' => $inventory->sourceIdentity,
            'linked_worktree_paths' => [], 'source_digest' => $inventory->digest, 'runtime_published' => true,
            'source_root' => $instance->production_home, 'user' => $instance->production_user, 'group' => $instance->production_user,
            'run_teardown' => false, 'row_deleted_at' => null];

        return ['instance_id' => $instance->id, 'node_id' => $instance->node_id, 'project_id' => $instance->project_id,
            'name' => $instance->name, 'force' => $force, 'removal_id' => (string) Str::uuid(), 'inventory_digest' => $inventory->digest,
            'repository' => $instance->project->repository_url, 'repository_identity' => $instance->project->repository_identity,
            'members' => [$member], 'additional_route_ids' => $additional,
            'additional_routes' => array_map(fn (int $id): array => ['route_id' => $id,
                'node_id' => $this->route(Route::query()->findOrFail($id), $instance->node_id)['node_id']], $additional), 'production_routes' => $routes,
            'production_home' => $instance->production_home, 'production_user' => $instance->production_user,
            'release_layout' => $instance->usesProductionReleaseLayout(), 'production_detached' => false];
    }

    /** @return list<Descriptor> */
    public function descriptors(Operation $operation, int $id): array
    {
        $execution = app(InstanceLifecycleExecution::class);
        $home = ['repository' => $operation->input['repository'], 'user' => $operation->input['production_user'],
            'home' => $operation->input['production_home'], 'instance' => $id];
        $member = InstanceLifecycleInput::members($operation->input)[0];
        $descriptors = [];
        foreach (['source-prepare' => 'production-prepare', 'route-detach' => 'route-detach'] as $key => $action) {
            $descriptors[] = $execution->descriptor("removal-{$id}-{$key}", 'gateway', 'instance.lifecycle-state',
                ['action' => 'removal-'.$action, 'operation_id' => $operation->id, 'instance_id' => $id]);
        }
        $descriptors[] = $operation->input['release_layout'] === true && ($member['source_finalized_at'] ?? null) === null
            ? $execution->descriptor("removal-{$id}-source-finalize", 'instance_lifecycle', 'instance.clear-current-release',
                [...$home, 'relative_root' => $member['root'], 'application_suffix' => ProductionApplicationPaths::suffix(ValidatedData::string($member['root']))])
            : $execution->descriptor("removal-{$id}-source-finalize", 'gateway', 'instance.lifecycle-state',
                ['action' => 'removal-production-finalize', 'operation_id' => $operation->id, 'instance_id' => $id]);
        $descriptors[] = $execution->descriptor("removal-{$id}-home-remove", 'instance_lifecycle', 'instance.remove-production-home', $home);

        return $descriptors;
    }

    /** @return array<string,mixed> */
    public function transition(Operation $operation, string $action): array
    {
        $this->validate($operation);
        if ($action === 'removal-route-detach') {
            return $this->detach($operation);
        }
        $member = InstanceRemovalMember::query()->where('instance_removal_id', $operation->input['removal_id'])->firstOrFail();
        if ($action === 'removal-production-prepare') {
            $member->update(['source_prepared_at' => now()]);

            return ['prepared' => true];
        }
        $member->update(['source_finalized_at' => now(), 'finalization_receipt' => hash('sha256', "production-retained\0{$member->source_digest}")]);

        return ['finalized' => true];
    }

    public function identity(Instance $instance): void
    {
        $user = 'orbit-app-'.$instance->project_id;
        $home = '/home/'.$user;
        if (! $instance->placedOnAppProd() || $instance->production_user !== $user || $instance->production_home !== $home
            || ($instance->checkout_path !== $home && preg_match('#\A'.preg_quote($home.'/releases/', '#').'(?!\.\.?\z)[^/]+\z#D', $instance->checkout_path) !== 1)) {
            throw new ResourceOperationException('app-prod.identity_missing', 'The recorded production home or account is invalid.', 409);
        }
        if (Instance::query()->where('node_id', $instance->node_id)->where('production_user', $user)->whereKeyNot($instance->id)->exists()) {
            throw new ResourceOperationException('app-prod.user_in_use', 'Another Instance on the Node uses this production account.', 409);
        }
    }

    public function validate(Operation $operation): Instance
    {
        $instance = app(InstanceLifecycleState::class)->instance($operation);
        $this->identity($instance);
        if ($instance->production_home !== $operation->input['production_home'] || $instance->production_user !== $operation->input['production_user']) {
            throw $this->conflict();
        }
        $member = InstanceRemovalMember::query()->where('instance_removal_id', $operation->input['removal_id'])->first();
        if ($member !== null) {
            app(ProductionInstanceSourceRemoval::class)->revalidate($member);
        } elseif (app(ProductionInstanceSourceRemoval::class)->inventory($instance)->digest !== $operation->input['inventory_digest']) {
            throw $this->conflict();
        }

        $detached = ($operation->state['production_routes_detached'] ?? $operation->input['production_detached'] ?? false) === true;
        $expectedRoutes = [];
        if ($member?->route_cleared_at === null) {
            foreach ($this->routes($operation) as $route) {
                if (! $detached || count($route['targets']) === 1) {
                    $expectedRoutes[] = $route['id'];
                }
            }
        }
        if ($member === null) {
            $expectedRoutes = [...$expectedRoutes, ...ValidatedData::integerList($operation->input['additional_route_ids'])];
        }
        sort($expectedRoutes);
        if ($instance->routes()->orderBy('routes.id')->pluck('routes.id')->all() !== $expectedRoutes) {
            throw $this->conflict();
        }

        return $instance;
    }

    /** @return list<ProductionRoute> */
    public function routes(Operation $operation): array
    {
        return array_map(static fn (array $route): array => ['id' => ValidatedData::integer($route['id']),
            'targets' => ValidatedData::integerList($route['targets']), 'node_id' => ValidatedData::integer($route['node_id']),
            'nodes' => ValidatedData::integerList($route['nodes'])], InstanceLifecycleInput::members(['members' => $operation->input['production_routes'] ?? []]));
    }

    /** @return list<ChildIntent> */
    public function children(Operation $operation): array
    {
        $children = [];
        $execution = app(InstanceLifecycleChildren::class);
        $id = $operation->target_id;
        foreach ($this->routes($operation) as $route) {
            if (count(ValidatedData::integerList($route['targets'])) === 1 && $execution->ready($operation, "removal-{$id}-source-prepare")) {
                $children[] = ['key' => 'withdraw-production-'.$route['id'], 'kind' => 'route.withdraw',
                    'node_id' => ValidatedData::integer($route['node_id']), 'input' => ['route_id' => $route['id']]];
            }
            if (count(ValidatedData::integerList($route['targets'])) > 1 && $execution->ready($operation, "removal-{$id}-route-detach")) {
                foreach (ValidatedData::integerList($route['nodes']) as $node) {
                    $children[] = ['key' => 'publish-production-'.$route['id'].'-'.$node, 'kind' => 'route.caddy_converge', 'node_id' => $node,
                        'input' => ['reason' => 'Instance '.$id.' Route '.$route['id'].' removal']];
                }
            }
        }

        return $children;
    }

    /** @return array<string,mixed> */
    public function detach(Operation $operation): array
    {
        $instance = $this->validate($operation);
        DB::transaction(function () use ($operation, $instance): void {
            foreach ($this->routes($operation) as $snapshot) {
                $route = Route::query()->lockForUpdate()->findOrFail($snapshot['id']);
                $ids = $route->targets()->orderBy('position')->orderBy('id')->pluck('instance_id')->all();
                $expected = ValidatedData::integerList($snapshot['targets']);
                $detached = ($operation->state['production_routes_detached'] ?? $operation->input['production_detached'] ?? false) === true;
                $remaining = array_values(array_diff($expected, [$instance->id]));
                $current = $this->route($route, $instance->node_id);
                if ($current['node_id'] !== $snapshot['node_id'] || $current['nodes'] !== $snapshot['nodes']) {
                    throw $this->conflict();
                }
                if ($ids !== ($detached && count($expected) > 1 ? $remaining : $expected)) {
                    throw $this->conflict();
                }
                if (count($expected) > 1) {
                    $route->targets()->where('instance_id', $instance->id)->delete();
                    foreach ($route->targets()->orderBy('position')->orderBy('id')->get() as $position => $target) {
                        $target->update(['position' => $position]);
                    }
                }
            }
            app(OperationStore::class)->change($operation, ['state' => [...($operation->state ?? []), 'production_routes_detached' => true]]);
        });

        return ['detached' => true];
    }

    public function clear(Operation $operation, InstanceRemovalMember $member): void
    {
        $execution = app(InstanceLifecycleExecution::class);
        $execution->proven($operation, 'removal-'.$member->instance_id.'-route-detach');
        foreach ($this->routes($operation) as $snapshot) {
            if (count(ValidatedData::integerList($snapshot['targets'])) === 1) {
                $execution->assertChild($operation, 'withdraw-production-'.$snapshot['id'], 'route.withdraw');
                $route = Route::query()->find($snapshot['id']);
                if ($route !== null && $route->targets()->pluck('instance_id')->all() !== [$member->instance_id]) {
                    throw $this->conflict();
                }
                $route?->delete();
            } else {
                $route = Route::query()->lockForUpdate()->findOrFail($snapshot['id']);
                if ($route->targets()->orderBy('position')->orderBy('id')->pluck('instance_id')->all()
                    !== array_values(array_diff($snapshot['targets'], [$member->instance_id]))) {
                    throw $this->conflict();
                }
                foreach (ValidatedData::integerList($snapshot['nodes']) as $node) {
                    $child = $execution->assertChild($operation, 'publish-production-'.$snapshot['id'].'-'.$node, 'route.caddy_converge');
                    if (($child->result['ready'] ?? null) !== true) {
                        throw $this->conflict();
                    }
                }
            }
        }
        $retained = array_any($this->routes($operation), static fn (array $route): bool => count($route['targets']) > 1);
        $member->update(['route_cleared_at' => now(), 'route_outcome' => $member->route_id === null ? 'none' : ($retained ? 'retained' : 'deleted')]);
    }

    /** @return ProductionRoute */
    private function route(Route $route, int $departingNode): array
    {
        $route->loadMissing(['targets.instance.node', 'cluster.routerAssignment.node', 'cluster.ingressAssignment.node']);
        $node = $route->node_id ?? $route->cluster?->routerAssignment?->node_id;
        if ($node === null) {
            throw $this->conflict();
        }
        $nodes = [$departingNode, $node, ...$route->targets->map(fn ($target): int => $target->instance->node_id)->all()];
        if ($route->cluster?->ingressAssignment?->node_id !== null) {
            $nodes[] = $route->cluster->ingressAssignment->node_id;
        }

        return ['id' => $route->id, 'targets' => ValidatedData::integerList($route->targets()->orderBy('position')->orderBy('id')->pluck('instance_id')->all()),
            'node_id' => $node, 'nodes' => array_values(array_unique($nodes))];
    }

    private function conflict(): ResourceOperationException
    {
        return new ResourceOperationException('instance.removal_conflict', 'The production source or Route target set changed during removal.', 409);
    }
}
