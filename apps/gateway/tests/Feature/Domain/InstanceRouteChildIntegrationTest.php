<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceRemovalStatus;
use App\Domain\Instances\InstanceRemovalStep;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\SwampOperationExecutor;
use App\Domain\Routes\InstanceRuntimeExecution;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Facades\DB;

it('composes the real no-Route withdrawal child and proves the empty PHP pool convergence before success', function (): void {
    app()->instance(OperationKinds::class, new OperationKinds(app(), [
        ...OperationKinds::definitions(), ...require app_path('Domain/Instances/lifecycle-operation-kinds.php'),
    ]));
    $accounts = Mockery::mock(ManagedUserAccountResolver::class);
    $accounts->shouldReceive('resolve')->andReturn(new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));
    app()->instance(ManagedUserAccountResolver::class, $accounts);
    $node = Node::query()->create(['name' => 'withdraw-target', 'public_ssh_host' => '192.0.2.9',
        'wireguard_ip' => '10.44.0.9', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active']);
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Unrouted workspace', 'slug' => 'unrouted-workspace',
        'type' => 'monorepo', 'root' => '.', 'repository_url' => 'https://github.com/example/workspace.git']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'isolated',
        'status' => InstanceState::Active, 'checkout_path' => '/srv/apps/unrouted-workspace/isolated',
        'branch' => 'isolated', 'starting_commit' => str_repeat('c', 40)]);
    $removal = InstanceRemoval::query()->create(['id' => 'unrouted-proof-removal', 'requested_instance_id' => $instance->id,
        'requested_name' => $instance->name, 'force' => true, 'inventory_digest' => str_repeat('a', 64), 'total' => 1,
        'status' => InstanceRemovalStatus::Removing, 'current_step' => InstanceRemovalStep::SourcePreparation]);
    $removal->members()->create(['position' => 0, 'instance_id' => $instance->id, 'project_id' => $project->id, 'node_id' => $node->id,
        'name' => $instance->name, 'environment' => 'development', 'source_layout' => 'checkout',
        'repository_identity' => $project->repository_identity, 'checkout_path' => $instance->checkout_path, 'root' => '.',
        'branch' => 'isolated', 'starting_commit' => $instance->starting_commit, 'source_commit' => $instance->starting_commit,
        'common_repository_path' => $instance->checkout_path, 'source_identity' => 'unrouted:1',
        'linked_worktree_paths' => [$instance->checkout_path], 'source_digest' => str_repeat('b', 64)]);
    $instance->update(['status' => InstanceState::Removing]);
    $key = 'removal-'.$instance->id.'-source-prepare';
    $binding = ['transport' => 'instance_lifecycle', 'name' => 'instance.prepare-source-removal', 'step' => $key, 'input' => []];
    $parent = Operation::query()->create(['kind' => 'instance.destroy', 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $node->id, 'status' => 'running', 'worker' => 'parent', 'lock_key' => 'instance:'.$instance->id,
        'input' => ['instance_id' => $instance->id, 'members' => [['instance_id' => $instance->id, 'route_id' => null, 'run_teardown' => false]]],
        'state' => ['lifecycle_bindings' => [$key => $binding], 'executor_runs' => [$key => [...$binding, 'result' => ['prepared' => true]]]]]);
    $parent->steps()->create(['name' => $key, 'position' => 0, 'sequence' => 1, 'status' => 'succeeded', 'resumable' => true]);
    $child = DB::transaction(fn (): Operation => app(OperationChildren::class)->admit($parent, ['key' => 'withdraw-instance-'.$instance->id])['operation']);
    expect($child->kind)->toBe('route.withdraw_instance')->and($child->node_id)->toBe($instance->node_id)
        ->and($child->input)->toBe(['instance_id' => $instance->id])->and($child->parent_operation_id)->toBe($parent->id);

    $executor = app(SwampOperationExecutor::class);
    $operation = $executor->claim('route.withdraw_instance', 'withdraw-worker', $node->id, $child->id);
    expect(fn () => $executor->complete($operation, ['worker' => 'withdraw-worker', 'status' => 'succeeded', 'result' => ['ready' => true]], $node->id))
        ->toThrow(ResourceOperationException::class);
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldReceive('run')->once()->withArgs(fn (Node $target, string $name, array $input): bool => $target->id === $instance->node_id
        && $name === 'php.converge-shared-pools' && $input['configurations'] instanceof stdClass && (array) $input['configurations'] === [])
        ->andReturn(['missing_pools' => [], 'stdout' => 'private-runtime-details']);
    app()->instance(AgentCommandRunner::class, $runner);
    $performed = [];
    do {
        $descriptors = app(InstanceRuntimeExecution::class)->context($operation->refresh())['descriptors'];
        $next = array_find($descriptors, static fn (array $descriptor): bool => ! in_array($descriptor['key'], $performed, true));
        if ($next === null) {
            break;
        }
        $run = ['worker' => 'withdraw-worker', 'key' => $next['key'], 'transport' => $next['transport'], 'name' => $next['name'], 'input' => $next['input']];
        $result = $executor->run($operation, $run, $node->id);
        expect($executor->run($operation, $run, $node->id))->toBe($result);
        $executor->step($operation, ['worker' => 'withdraw-worker', 'key' => $next['key'].'.finish',
            'name' => $next['step'], 'status' => 'succeeded', 'result' => $result], $node->id);
        $performed[] = $next['key'];
    } while (true);
    $executor->complete($operation, ['worker' => 'withdraw-worker', 'status' => 'succeeded', 'result' => ['ready' => true]], $node->id);

    expect($performed)->toBe(['instance-runtime-prepare', 'instance-php-withdraw', 'instance-runtime-proof']);
    expect($operation->refresh()->status->value)->toBe('succeeded');
    expect(json_encode($operation->toArray()))->not->toContain('private-runtime-details');
    expect(Route::query()->count())->toBe(0)->and($parent->refresh()->lock_key)->not->toBeNull();
    $this->assertModelExists($instance);
});
