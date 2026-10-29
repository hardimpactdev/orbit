<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceDestroyAction;
use App\Domain\Instances\InstanceLifecycleExecution;
use App\Domain\Instances\InstanceRemovalState;
use App\Domain\Instances\ProductionRemovalIntent;
use App\Domain\Instances\RemovalLifecycleExecution;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationKinds;
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

beforeEach(function (): void {
    app()->instance(OperationKinds::class, new OperationKinds(app(), [
        ...OperationKinds::definitions(), ...require app_path('Domain/Instances/lifecycle-operation-kinds.php'),
    ]));
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $agents);
    $cluster = Cluster::query()->create(['name' => 'production-removal', 'state' => 'active']);
    $this->nodes = collect(['app-prod', 'app-prod', 'router', 'ingress'])->map(function (string $role, int $index) use ($cluster): Node {
        $node = Node::query()->create(['cluster_id' => $cluster->id, 'name' => 'proof-'.$index, 'status' => 'active',
            'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.'.($index + 20), 'wireguard_ip' => '10.44.0.'.($index + 20)]);
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

it('refuses a foreign home, shared account or missing dedicated PHP identity before admission', function (string $change): void {
    if ($change === 'shared') {
        $this->route->targets()->where('instance_id', $this->survivor->id)->delete();
        $other = Project::query()->create(['name' => 'Other', 'slug' => 'other', 'type' => 'monorepo',
            'repository_url' => 'https://example.test/other.git', 'default_branch' => 'main', 'root' => 'public']);
        $this->survivor->update(['node_id' => $this->instance->node_id, 'project_id' => $other->id, 'checkout_path' => '/home/shared-proof']);
    } elseif ($change === 'home') {
        $this->instance->update(['production_home' => '/home/someone-else']);
    } else {
        $this->instance->project->update(['type' => 'laravel-app']);
        $this->instance->update(['selected_php_version' => '8.5']);
    }
    expect(fn () => ($this->admit)())->toThrow(ResourceOperationException::class);
    expect(Operation::query()->count())->toBe(0);
})->with(['home', 'shared', 'php']);

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
        $node = Node::query()->create(['name' => 'moved', 'cluster_id' => $this->nodes[0]->cluster_id, 'status' => 'active', 'platform' => 'linux',
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
