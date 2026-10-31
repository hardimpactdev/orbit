<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceDestroyAction;
use App\Actions\Operations\CancelOperationAction;
use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\InstanceLifecycleExecution;
use App\Domain\Instances\InstanceRemovalState;
use App\Domain\Instances\ProductionRemovalIntent;
use App\Domain\Instances\RemovalLifecycleExecution;
use App\Domain\Operations\OperationChildAdmission;
use App\Domain\Operations\OperationChildIntent;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\OperationExecutors;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\SwampOperationExecutor;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\RouteSwampGatewayFixture;

/** Test the lifecycle boundary through real stamped children without executing Route publication. */
final readonly class ProductionRemovalPublicationAdmission implements OperationChildAdmission
{
    public function admit(Operation $parent, array $intent): Operation
    {
        return app(StartOperationAction::class)->execute('route.caddy_converge', 'node', $intent['node_id'],
            $intent['node_id'], $intent['input'], null, null);
    }
}

/** Other owning families are registered here but never executed by these admission tests. */
final readonly class ProductionRemovalFixtureAdmission implements OperationChildAdmission
{
    public function admit(Operation $parent, array $intent): Operation
    {
        throw new LogicException('This test must stop before another owning family executes.');
    }
}

/** Exercise existing invalid rows without changing the production persistence contract. */
function corruptProductionRemovalPlacement(Closure $change): void
{
    $names = ['routes_contract_update', 'production_route_target_nodes_update', 'production_route_target_roles_update',
        'instances_production_placement_update', 'production_route_target_instances_update', 'route_targets_contract_update', 'route_targets_contract_insert', 'route_targets_active_update'];
    $triggers = DB::table('sqlite_master')->where('type', 'trigger')->whereIn('name', $names)->get();
    foreach ($triggers as $trigger) {
        DB::statement('DROP TRIGGER '.$trigger->name);
    }
    try {
        $change();
    } finally {
        foreach ($triggers as $trigger) {
            DB::statement($trigger->sql);
        }
    }
}

beforeEach(function (): void {
    $definitions = [
        ...OperationKinds::definitions(), ...require app_path('Domain/Instances/lifecycle-operation-kinds.php'),
        'route.caddy_converge' => ['executor' => 'swamp', 'admission' => ProductionRemovalPublicationAdmission::class,
            'runs' => [['transport' => 'gateway', 'name' => 'test.publication']],
            'completion_contract' => ['step' => 'publish', 'transport' => 'gateway', 'name' => 'test.publication', 'result' => ['ready' => true]],
            'result_fields' => ['ready', 'error_code']],
    ];
    foreach ($definitions['instance.destroy']['children'] as $child) {
        $definition = $definitions[$child['kind']] ?? null;
        if (! is_array($definition) || ! isset($definition['admission'])) {
            $definitions[$child['kind']] = ['executor' => 'swamp', 'admission' => ProductionRemovalFixtureAdmission::class,
                'runs' => [['transport' => 'gateway', 'name' => 'test.retained-owner']],
                'completion_contract' => ['step' => 'fixture', 'transport' => 'gateway', 'name' => 'test.retained-owner', 'result' => []],
                'result_fields' => []];
        }
    }
    app()->instance(OperationKinds::class, new OperationKinds(app(), $definitions));
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $agents);
    $cluster = Cluster::query()->create(['name' => 'production-removal', 'state' => 'active']);
    $this->nodes = collect(['app-prod', 'app-prod', 'router', 'ingress'])->map(function (string $role, int $index) use ($cluster): Node {
        $node = Node::query()->create(['cluster_id' => $cluster->id, 'name' => 'proof-'.$index, 'status' => 'active',
            'architecture' => 'x86_64', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.'.($index + 20), 'wireguard_ip' => '10.44.0.'.($index + 20)]);
        $node->roles()->create(['role' => $role, 'status' => 'active', 'cluster_id' => in_array($role, ['router', 'ingress'], true) ? $cluster->id : null]);

        return $node;
    });
    $project = Project::query()->create(['name' => 'Production proof', 'slug' => 'production-proof', 'type' => 'monorepo',
        'repository_url' => 'https://example.test/production-proof.git', 'default_branch' => 'main', 'root' => 'public']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->nodes[0]->id,
        'name' => 'production', 'status' => 'active', 'source_layout' => 'checkout', 'environment' => 'production',
        'production_user' => 'orbit-app-'.$project->id, 'production_home' => '/home/orbit-app-'.$project->id,
        'checkout_path' => '/home/orbit-app-'.$project->id.'/releases/initial', 'starting_commit' => str_repeat('a', 40)]);
    $this->survivor = $this->instance->replicate();
    $this->survivor->name = 'survivor';
    $this->survivor->node_id = $this->nodes[1]->id;
    $this->survivor->save();
    $this->route = Route::query()->create(['project_id' => $project->id, 'cluster_id' => $cluster->id,
        'domain' => 'production-proof.test', 'publication' => 'public', 'provenance' => 'explicit', 'status' => 'pending']);
    $this->route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    $this->route->targets()->create(['instance_id' => $this->survivor->id, 'position' => 1]);
    $this->route->update(['status' => 'active']);
    $this->admit = fn (): Operation => app(StartInstanceDestroyAction::class)->execute($this->instance->fresh(), true);
    $this->prove = function (Operation $operation, string $key, array $result): void {
        $descriptor = array_find(app(InstanceLifecycleExecution::class)->context($operation)['descriptors'], fn (array $entry): bool => $entry['key'] === $key);
        $state = $operation->state ?? [];
        $state['executor_runs'][$key] = [...$descriptor, 'result' => $result];
        $state['lifecycle_bindings'][$key] = $descriptor;
        $operation->update(['state' => $state]);
        $operation->steps()->updateOrCreate(['name' => $key], ['position' => $operation->steps()->count() + 1, 'sequence' => 1, 'status' => 'succeeded', 'resumable' => true]);
    };
});

it('records production source, shared targets and every publication Node without executing anything', function (): void {
    $operation = ($this->admit)();
    expect($operation->input['members'][0]['run_teardown'])->toBeFalse()
        ->and($operation->input['production_routes'][0]['targets'])->toBe([$this->instance->id, $this->survivor->id])
        ->and($operation->input['production_routes'][0]['nodes'])->toEqualCanonicalizing($this->nodes->pluck('id')->all())
        ->and($this->instance->fresh()->status->value)->toBe('active')
        ->and(InstanceRemoval::query()->count())->toBe(0);
});

it('refuses cancellation after accepted removal even when its acceptance reply was lost', function (bool $receiptSaved): void {
    $operation = ($this->admit)();
    app(InstanceRemovalState::class)->transition($operation, ['action' => 'removal-accept']);
    if ($receiptSaved) {
        ($this->prove)($operation, 'removal-accept', ['accepted' => true, 'removal_id' => $operation->input['removal_id']]);
    }
    $before = $operation->fresh()->getAttributes();

    expect(fn () => app(CancelOperationAction::class)->execute($operation))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.cancel_refused'));

    expect($operation->fresh()->getAttributes())->toBe($before);
    expect($this->instance->fresh()->status->value)->toBe('removing');
})->with([true, false]);

it('permits cancellation before acceptance and refuses a later acceptance dispatch', function (): void {
    $operation = ($this->admit)();
    app(CancelOperationAction::class)->execute($operation);

    expect(fn () => app(InstanceRemovalState::class)->transition($operation, ['action' => 'removal-accept']))
        ->toThrow(ResourceOperationException::class);
    $this->assertDatabaseCount('instance_removals', 0);
    expect($this->instance->fresh()->status->value)->toBe('active');
});

it('refuses a Retiring Route without its own successful stamped withdrawal', function (string $proof): void {
    bind_task_node_reachability();
    app()->instance(AgentCommandRunner::class, new FakeAgentCommandRunner);
    $this->route->targets()->where('instance_id', $this->survivor->id)->delete();
    $operation = ($this->admit)();
    app(InstanceRemovalState::class)->transition($operation, ['action' => 'removal-accept']);
    app(ProductionRemovalIntent::class)->transition($operation, 'removal-production-prepare');
    ($this->prove)($operation, 'removal-'.$this->instance->id.'-source-prepare', ['prepared' => true]);
    if ($proof !== 'absent') {
        $child = app(OperationChildren::class)->admit($operation, ['key' => 'withdraw-production-'.$this->route->id])['operation'];
        $child->update(['status' => $proof === 'failed' ? 'failed' : 'succeeded']);
        if ($proof === 'unbound') {
            $child->forceFill(['child_fingerprint' => str_repeat('f', 64)])->save();
        }
    }
    $this->route->update(['status' => 'retiring']);

    expect(fn () => app(ProductionRemovalIntent::class)->detach($operation))->toThrow(ResourceOperationException::class);
    expect($this->route->targets()->sole()->instance_id)->toBe($this->instance->id);
})->with(['absent', 'failed', 'unbound']);

it('refuses a foreign home, shared account or missing dedicated PHP identity before admission', function (string $change): void {
    if ($change === 'shared') {
        $this->route->targets()->where('instance_id', $this->survivor->id)->delete();
        $other = Project::query()->create(['name' => 'Other', 'slug' => 'other', 'type' => 'monorepo',
            'repository_url' => 'https://example.test/other.git', 'default_branch' => 'main', 'root' => 'public']);
        $this->survivor->update(['node_id' => $this->instance->node_id, 'project_id' => $other->id, 'checkout_path' => '/home/shared-proof']);
    } elseif ($change === 'route') {
        $this->route->update(['status' => 'pending']);
    } elseif ($change === 'home') {
        $this->instance->update(['production_home' => '/home/someone-else']);
    } else {
        $this->instance->project->update(['type' => 'laravel-app']);
        $this->instance->update(['selected_php_version' => '8.5']);
    }
    expect(fn () => ($this->admit)())->toThrow(ResourceOperationException::class);
    expect(Operation::query()->count())->toBe(0);
})->with(['home', 'shared', 'php', 'route']);

it('detaches only the admitted target and cannot clear its checkpoint before exact stamped publication succeeds', function (): void {
    $operation = ($this->admit)();
    app(InstanceRemovalState::class)->transition($operation, ['action' => 'removal-accept']);
    $prefix = 'removal-'.$this->instance->id;
    app(ProductionRemovalIntent::class)->transition($operation, 'removal-production-prepare');
    ($this->prove)($operation, $prefix.'-source-prepare', ['prepared' => true]);
    expect(app(ProductionRemovalIntent::class)->detach($operation))->toBe(['detached' => true]);
    expect(app(ProductionRemovalIntent::class)->detach($operation))->toBe(['detached' => true]);
    expect($this->route->targets()->pluck('instance_id')->all())->toBe([$this->survivor->id])
        ->and($this->route->targets()->value('position'))->toBe(0);
    ($this->prove)($operation, $prefix.'-route-detach', ['detached' => true]);
    $member = InstanceRemoval::query()->firstOrFail()->members()->firstOrFail();
    expect(fn () => app(ProductionRemovalIntent::class)->clear($operation, $member))->toThrow(ResourceOperationException::class);
    foreach (app(ProductionRemovalIntent::class)->children($operation) as $intent) {
        $child = DB::transaction(fn (): Operation => app(OperationChildren::class)->admit($operation, ['key' => $intent['key']])['operation']);
        $child->update(['status' => 'succeeded', 'result' => ['ready' => true]]);
    }
    app(ProductionRemovalIntent::class)->clear($operation, $member);
    expect($member->fresh()->route_cleared_at)->not->toBeNull()
        ->and($this->route->fresh())->not->toBeNull()->and($this->survivor->fresh())->not->toBeNull();
    $operation->update(['status' => 'failed', 'lock_key' => null]);
    $retry = ($this->admit)();
    expect($retry->input['removal_id'])->toBe($operation->input['removal_id'])
        ->and($retry->input['production_routes'])->toBe([])
        ->and($retry->input['members'][0]['route_cleared_at'])->not->toBeNull();
});

it('refuses changed targets and placement before detaching', function (string $change): void {
    $operation = ($this->admit)();
    if ($change === 'placement') {
        $node = Node::query()->create(['name' => 'moved', 'cluster_id' => $this->nodes[0]->cluster_id, 'status' => 'active', 'architecture' => 'x86_64', 'platform' => 'linux',
            'public_ssh_host' => '192.0.2.55', 'wireguard_ip' => '10.44.0.55']);
        $node->roles()->create(['role' => 'app-prod', 'status' => 'active']);
        $this->survivor->update(['node_id' => $node->id]);
    } else {
        $this->route->targets()->where('instance_id', $this->survivor->id)->delete();
    }
    expect(fn () => app(ProductionRemovalIntent::class)->detach($operation))->toThrow(ResourceOperationException::class);
    expect($this->route->targets()->where('instance_id', $this->instance->id)->exists())->toBeTrue();
})->with(['targets', 'placement']);

it('binds home and current commands and refuses premature home deletion or unproven current receipts', function (): void {
    $this->route->targets()->delete();
    $this->route->delete();
    $operation = ($this->admit)();
    app(InstanceRemovalState::class)->transition($operation, ['action' => 'removal-accept']);
    $descriptors = collect(app(InstanceLifecycleExecution::class)->context($operation)['descriptors'])->keyBy('key');
    $prefix = 'removal-'.$this->instance->id;
    $home = $descriptors[$prefix.'-home-remove'];
    expect($home['input']['home'])->toBe($this->instance->production_home)->and($home['input']['instance'])->toBe($this->instance->id);
    expect(fn () => app(RemovalLifecycleExecution::class)->beforeRun($operation, ['worker' => 'proof', ...$home]))->toThrow(ResourceOperationException::class);
    $current = $descriptors[$prefix.'-source-finalize'];
    expect($current['name'])->toBe('instance.clear-current-release');
    expect(app(RemovalLifecycleExecution::class)->received($operation, ['worker' => 'proof', ...$current], ['current' => 'still-serving']))->toHaveKey('error_code');
    expect(InstanceRemoval::query()->firstOrFail()->members()->firstOrFail()->source_finalized_at)->toBeNull();
    app(ProductionRemovalIntent::class)->transition($operation, 'removal-production-prepare');
    app(ProductionRemovalIntent::class)->detach($operation);
    ($this->prove)($operation, $prefix.'-route-detach', ['detached' => true]);
    app(ProductionRemovalIntent::class)->clear($operation, InstanceRemoval::query()->firstOrFail()->members()->firstOrFail());
    expect(app(RemovalLifecycleExecution::class)->received($operation, ['worker' => 'proof', ...$current], ['current' => null]))->toBe(['finalized' => true]);
});

it('refuses unsafe production Route state at admission and again before locked detach', function (string $change, bool $afterAdmission): void {
    $operation = $afterAdmission ? ($this->admit)() : null;
    corruptProductionRemovalPlacement(fn () => match ($change) {
        'implicit' => DB::table('routes')->where('id', $this->route->id)->update(['provenance' => 'generated']),
        'inactive-instance' => DB::table('instances')->where('id', $this->survivor->id)->update(['status' => 'reserved']),
        'inactive-node' => DB::table('nodes')->where('id', $this->nodes[1]->id)->update(['status' => 'failed']),
        'inactive-role' => $this->nodes[1]->roles()->update(['status' => 'failed']),
        'other-cluster' => DB::table('nodes')->where('id', $this->nodes[1]->id)->update(['cluster_id' => null]),
        'duplicate-node' => DB::table('instances')->where('id', $this->survivor->id)->update(['node_id' => $this->instance->node_id, 'production_user' => 'co-target-proof',
            'production_home' => '/home/co-target-proof', 'checkout_path' => '/home/co-target-proof/releases/initial']),
        'noncontiguous' => $this->route->targets()->where('instance_id', $this->survivor->id)->update(['position' => 3]),
    });
    expect(fn () => $operation === null ? ($this->admit)() : app(ProductionRemovalIntent::class)->detach($operation))
        ->toThrow(ResourceOperationException::class);
    expect($this->route->targets()->where('instance_id', $this->instance->id)->exists())->toBeTrue();
})->with(['implicit', 'inactive-instance', 'inactive-node', 'inactive-role', 'other-cluster', 'duplicate-node', 'noncontiguous'])->with([false, true]);

it('allows only a private single-target production Route on its active unclustered Node', function (string $change, bool $afterAdmission): void {
    $this->route->targets()->where('instance_id', $this->survivor->id)->delete();
    DB::table('nodes')->where('id', $this->nodes[0]->id)->update(['cluster_id' => null]);
    DB::table('routes')->where('id', $this->route->id)->update(['cluster_id' => null, 'node_id' => $this->nodes[0]->id, 'publication' => 'private']);
    $operation = $afterAdmission ? ($this->admit)() : null;
    corruptProductionRemovalPlacement(fn () => match ($change) {
        'public' => DB::table('routes')->where('id', $this->route->id)->update(['publication' => 'public']),
        'wrong-node' => DB::table('routes')->where('id', $this->route->id)->update(['node_id' => $this->nodes[1]->id]),
        'inactive-node' => DB::table('nodes')->where('id', $this->nodes[0]->id)->update(['status' => 'failed']),
        'inactive-role' => $this->nodes[0]->roles()->update(['status' => 'failed']),
        'multiple-targets' => $this->route->targets()->create(['instance_id' => $this->survivor->id, 'position' => 1]),
    });
    expect(fn () => $operation === null ? ($this->admit)() : app(ProductionRemovalIntent::class)->detach($operation))
        ->toThrow(ResourceOperationException::class);
    expect($this->route->targets()->where('instance_id', $this->instance->id)->exists())->toBeTrue();
})->with(['public', 'wrong-node', 'inactive-node', 'inactive-role', 'multiple-targets'])->with([false, true]);

it('retains a shared Route detach checkpoint across repeated interrupted removals', function (): void {
    $operation = ($this->admit)();
    app(InstanceRemovalState::class)->transition($operation, ['action' => 'removal-accept']);
    app(ProductionRemovalIntent::class)->transition($operation, 'removal-production-prepare');
    app(ProductionRemovalIntent::class)->detach($operation);
    $operation->update(['status' => 'failed', 'lock_key' => null]);
    $retry = ($this->admit)();
    expect($retry->input['production_detached'])->toBeTrue();
    $retry->update(['status' => 'failed', 'lock_key' => null]);
    $second = ($this->admit)();
    expect($second->input['production_detached'])->toBeTrue();
    expect(app(ProductionRemovalIntent::class)->detach($second))->toBe(['detached' => true]);
});

it('requires the successful owned database child after runtime cleanup before removing the production home', function (): void {
    $this->route->targets()->delete();
    $this->route->delete();
    $operation = ($this->admit)();
    app(InstanceRemovalState::class)->transition($operation, ['action' => 'removal-accept']);
    $prefix = 'removal-'.$this->instance->id;
    ($this->prove)($operation, 'removal-accept', ['accepted' => true]);
    ($this->prove)($operation, $prefix.'-runtime-intent', ['process_ids' => [], 'schedule_ids' => []]);
    $child = function (string $key, string $kind) use ($operation): void {
        $input = ['instance_id' => $this->instance->id];
        $row = Operation::query()->create(['kind' => $kind, 'target_type' => 'instance', 'target_id' => $this->instance->id,
            'node_id' => $operation->node_id, 'status' => 'succeeded', 'input' => $input]);
        $snapshot = ['intent' => ['key' => $key, 'kind' => $kind, 'node_id' => $operation->node_id, 'input' => $input],
            'admitted' => ['parent_operation_id' => $operation->id, 'key' => $key, 'kind' => $kind, 'node_id' => $operation->node_id,
                'target_type' => 'instance', 'target_id' => $this->instance->id, 'input' => $input, 'run_constraints' => []]];
        $row->forceFill(['parent_operation_id' => $operation->id, 'child_key' => $key, 'child_admission' => $snapshot,
            'child_fingerprint' => OperationChildIntent::fingerprint($snapshot)])->save();
    };
    foreach (['cleanup-instance-' => 'route.cleanup_instance'] as $key => $kind) {
        $child($key.$this->instance->id, $kind);
    }
    $home = array_find(app(InstanceLifecycleExecution::class)->context($operation)['descriptors'], fn (array $entry): bool => $entry['key'] === $prefix.'-home-remove');
    expect(fn () => app(RemovalLifecycleExecution::class)->beforeRun($operation, ['worker' => 'proof', ...$home]))->toThrow(ResourceOperationException::class);
    $child('remove-databases-'.$this->instance->id, 'database.destroy_owned');
    app(RemovalLifecycleExecution::class)->beforeRun($operation, ['worker' => 'proof', ...$home]);
    expect($operation->children()->count())->toBe(2);
});

it('uses the effective unclustered placement when the Node belongs to an inactive Cluster', function (): void {
    $this->route->targets()->where('instance_id', $this->survivor->id)->delete();
    DB::table('routes')->where('id', $this->route->id)->update(['cluster_id' => null, 'node_id' => $this->nodes[0]->id, 'publication' => 'private']);
    Cluster::query()->whereKey($this->nodes[0]->cluster_id)->update(['state' => 'inactive']);

    expect(($this->admit)()->input['production_routes'][0]['targets'])->toBe([$this->instance->id]);
});

it('accepts the native already-absent home receipt so removal can retry after row deletion failed', function (mixed $removed, bool $converged): void {
    $operation = ($this->admit)();
    $descriptor = array_find(app(InstanceLifecycleExecution::class)->context($operation)['descriptors'],
        fn (array $entry): bool => $entry['key'] === 'removal-'.$this->instance->id.'-home-remove');

    $receipt = app(RemovalLifecycleExecution::class)->received($operation, ['worker' => 'proof', ...$descriptor], ['removed' => $removed]);

    expect($receipt)->toBe($converged ? ['home_removed' => true] : ['error_code' => 'app-prod.source_cleanup_failed']);
})->with(['removed' => [true, true], 'already absent' => [false, true], 'missing proof' => [null, false], 'untyped proof' => [0, false]]);

it('preserves a failed native home receipt even when it includes removed false', function (): void {
    $operation = ($this->admit)();
    $descriptor = array_find(app(InstanceLifecycleExecution::class)->context($operation)['descriptors'],
        fn (array $entry): bool => $entry['key'] === 'removal-'.$this->instance->id.'-home-remove');
    $result = ['error_code' => 'app-prod.user_in_use', 'removed' => false];

    expect(app(RemovalLifecycleExecution::class)->received($operation, ['worker' => 'proof', ...$descriptor], $result))->toBe($result);
});

it('refuses a new production co-target before prepare or before withdrawal child admission', function (bool $afterPrepare): void {
    $this->route->targets()->where('instance_id', $this->survivor->id)->delete();
    $operation = ($this->admit)();
    app(InstanceRemovalState::class)->transition($operation, ['action' => 'removal-accept']);
    $prefix = 'removal-'.$this->instance->id;
    if ($afterPrepare) {
        app(ProductionRemovalIntent::class)->transition($operation, 'removal-production-prepare');
        ($this->prove)($operation, $prefix.'-source-prepare', ['prepared' => true]);
    }
    $this->route->targets()->create(['instance_id' => $this->survivor->id, 'position' => 1]);
    $member = InstanceRemoval::query()->sole()->members()->sole();

    expect(fn () => $afterPrepare
        ? DB::transaction(fn (): array => app(OperationChildren::class)->admit($operation, ['key' => 'withdraw-production-'.$this->route->id]))
        : app(ProductionRemovalIntent::class)->transition($operation, 'removal-production-prepare'))
        ->toThrow(ResourceOperationException::class);

    expect($operation->children()->count())->toBe(0);
    expect($this->route->fresh()->status->value)->toBe('active');
    expect($this->route->targets()->orderBy('position')->pluck('instance_id')->all())->toBe([$this->instance->id, $this->survivor->id]);
    if (! $afterPrepare) {
        expect($member->refresh()->source_prepared_at)->toBeNull();
    }
})->with(['before prepare' => [false], 'after prepare' => [true]]);

it('finishes single-target production removal through the real withdrawal workflow', function (): void {
    $this->route->targets()->where('instance_id', $this->survivor->id)->delete();
    $gateway = Node::query()->create(['name' => 'removal-gateway', 'architecture' => 'x86_64', 'platform' => 'linux', 'status' => 'active', 'public_ssh_host' => '192.0.2.250', 'wireguard_ip' => '10.254.254.250']);
    $gateway->roles()->create(['role' => 'gateway', 'status' => 'active']);
    $definition = app(OperationKinds::class)->definition('instance.destroy');
    $fixture = new RouteSwampGatewayFixture($this);
    app()->instance(OperationKinds::class, new OperationKinds(app(), [...OperationKinds::definitions(), 'instance.destroy' => $definition]));
    foreach ([OperationExecutors::class, OperationExecutionContract::class, OperationChildren::class, SwampOperationExecutor::class] as $class) {
        app()->forgetInstance($class);
    }
    $fixture->agents->answer('instance.clear-current-release', ['current' => null]);
    $fixture->agents->answer('instance.remove-production-home', ['removed' => true]);
    $peer = $this->instance->node_id;
    config(['operation_executor.worker_node_ids' => [...config('operation_executor.worker_node_ids'), $peer]]);
    $executor = app(SwampOperationExecutor::class);
    $root = ($this->admit)();
    $root = $executor->claim('instance.destroy', 'removal-proof', $peer, $root->id);
    $run = function (string $key) use (&$root, $executor, $peer): array {
        $descriptor = array_find(app(InstanceLifecycleExecution::class)->context($root->refresh())['descriptors'],
            static fn (array $entry): bool => $entry['key'] === $key);
        $result = $executor->run($root, ['worker' => 'removal-proof', ...$descriptor], $peer);
        expect($result)->not->toHaveKey('error_code');
        $executor->step($root, ['worker' => 'removal-proof', 'key' => $key.'.finish', 'name' => $key, 'status' => 'succeeded', 'result' => $result], $peer);

        return $result;
    };
    $child = function (string $key) use (&$root, $executor, $fixture, $peer): void {
        $operation = $executor->child($root, ['worker' => 'removal-proof', 'key' => $key], $peer)['operation'];
        $executor->wait($root, 'removal-proof', [$key], $peer);
        if ($operation->kind === 'database.destroy_owned') {
            $operation = $executor->claim($operation->kind, 'database-proof', $peer, $operation->id);
            foreach (app(OperationExecutionContract::class)->execution($operation)->context($operation)['descriptors'] as $descriptor) {
                $result = $executor->run($operation, ['worker' => 'database-proof', ...$descriptor], $peer);
                $executor->step($operation, ['worker' => 'database-proof', 'key' => $descriptor['key'].'.finish',
                    'name' => $descriptor['step'], 'status' => 'succeeded', 'result' => $result], $peer);
            }
            $executor->complete($operation, ['worker' => 'database-proof', 'status' => 'succeeded', 'result' => $result], $peer);
        } else {
            $fixture->run($operation);
        }
        expect($operation->refresh()->status->value)->toBe('succeeded');
        $root = $executor->claim('instance.destroy', 'removal-proof', $peer, $root->id);
    };
    $prefix = 'removal-'.$this->instance->id;
    foreach (['removal-inspect', 'removal-revalidate', 'removal-accept', $prefix.'-source-prepare'] as $key) {
        $run($key);
    }
    $child('withdraw-production-'.$this->route->id);
    expect($this->route->fresh()->status->value)->toBe('retiring');
    foreach ([$prefix.'-route-detach', $prefix.'-route-clear', $prefix.'-source-finalize', $prefix.'-runtime-intent'] as $key) {
        $run($key);
    }
    $child('cleanup-instance-'.$this->instance->id);
    $run($prefix.'-database-intent');
    $child('remove-databases-'.$this->instance->id);
    $run($prefix.'-home-remove');
    $run($prefix.'-row-delete');
    $result = $run('lifecycle-finish');
    $executor->complete($root, ['worker' => 'removal-proof', 'status' => 'succeeded', 'result' => $result], $peer);

    $this->assertDatabaseMissing('instances', ['id' => $this->instance->id]);
    $this->assertDatabaseMissing('routes', ['id' => $this->route->id]);
    expect($root->refresh()->status->value)->toBe('succeeded');
    expect(InstanceRemoval::query()->sole()->status->value)->toBe('completed');
});

it('resumes a Retiring Route using the same removal proven withdrawal and preserves its preparation checkpoint', function (): void {
    bind_task_node_reachability();
    app()->instance(AgentCommandRunner::class, new FakeAgentCommandRunner);
    $this->route->targets()->where('instance_id', $this->survivor->id)->delete();
    $operation = ($this->admit)();
    app(InstanceRemovalState::class)->transition($operation, ['action' => 'removal-accept']);
    app(ProductionRemovalIntent::class)->transition($operation, 'removal-production-prepare');
    ($this->prove)($operation, 'removal-'.$this->instance->id.'-source-prepare', ['prepared' => true]);
    $withdrawal = app(OperationChildren::class)->admit($operation, ['key' => 'withdraw-production-'.$this->route->id])['operation'];
    $withdrawal->update(['status' => 'succeeded']);
    $this->route->update(['status' => 'retiring']);
    $member = InstanceRemoval::query()->sole()->members()->sole();
    $preparedAt = $member->source_prepared_at;
    $operation->update(['status' => 'failed', 'lock_key' => null]);
    $this->travel(10)->seconds();

    $retry = ($this->admit)();
    app(InstanceRemovalState::class)->transition($retry, ['action' => 'removal-accept']);
    expect(app(ProductionRemovalIntent::class)->transition($retry, 'removal-production-prepare'))->toBe(['prepared' => true]);
    expect(app(ProductionRemovalIntent::class)->detach($retry))->toBe(['detached' => true]);

    expect($member->refresh()->source_prepared_at->equalTo($preparedAt))->toBeTrue();
    expect($this->route->fresh()->status->value)->toBe('retiring');
    expect($this->route->targets()->sole()->instance_id)->toBe($this->instance->id);
});
