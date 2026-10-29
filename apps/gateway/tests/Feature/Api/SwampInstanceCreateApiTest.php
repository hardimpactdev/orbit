<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceDestinationGuard;
use App\Domain\Instances\InstanceLifecycleExecution;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceRemoval;
use App\Domain\Instances\Removal\InstanceSourceInventory;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationChildIntent;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\SwampOperationExecutor;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\ProjectNodeExclusion;
use App\Models\Route;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    app()->instance(OperationKinds::class, new OperationKinds(app(), [
        ...OperationKinds::definitions(), ...require app_path('Domain/Instances/lifecycle-operation-kinds.php'),
    ]));
    $this->node = Node::query()->create(['name' => 'admission-dev', 'user' => 'orbit', 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9', 'status' => 'active', 'tld' => 'test',
        'settings' => ['apps' => ['path' => '/srv/apps']]]);
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $this->node->accessibleNodes()->attach($this->node);
    $operator = Node::query()->create(['name' => 'admission-operator', 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2', 'status' => 'active']);
    $operator->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->project = Project::query()->create(['name' => 'Admission', 'slug' => 'admission', 'type' => 'laravel-app',
        'root' => 'public', 'repository_url' => 'https://github.com/example/admission.git', 'default_branch' => 'main']);
    $this->payload = ['project_id' => $this->project->id, 'node_id' => $this->node->id, 'name' => 'proof'];
    $accounts = Mockery::mock(ManagedUserAccountResolver::class);
    $accounts->shouldReceive('resolve')->andReturn(new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));
    app()->instance(ManagedUserAccountResolver::class, $accounts);
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $agents);
    $this->destinations = Mockery::mock(InstanceDestinationGuard::class);
    app()->instance(InstanceDestinationGuard::class, $this->destinations);
    Queue::fake();
    $this->withServerVariables(['REMOTE_ADDR' => $operator->wireguard_ip]);
});

afterEach(function (): void {
    Queue::assertNothingPushed();
});

it('reserves the private Route and exact target association before any source effect', function (): void {
    $id = $this->withHeader('Idempotency-Key', 'create-private-route')->postJson('/api/v1/instances', $this->payload)
        ->assertAccepted()->assertJsonPath('data.kind', 'instance.create')->assertJsonPath('data.status', 'queued')->json('data.id');
    $this->postJson('/api/v1/instances', $this->payload)->assertAccepted()->assertJsonPath('data.id', $id);

    $instance = Instance::query()->sole();
    $route = $instance->routes()->sole();
    expect($instance->status->value)->toBe('reserved')->and($instance->first_setup_pending)->toBeTrue();
    expect($route->domain)->toBe('proof.admission.test')->and($route->publication->value)->toBe('private')
        ->and($route->status->value)->toBe('pending')->and($route->targets()->sole()->instance_id)->toBe($instance->id);
    expect(Operation::query()->sole()->input)->toMatchArray(['instance_id' => $instance->id, 'route_id' => $route->id,
        'route_domain' => $route->domain, 'checkout' => '/srv/apps/admission/proof', 'root' => null]);
});

it('refuses changed branch override root or layout on retry without changing reserved intent', function (string $change): void {
    $payload = [...$this->payload, 'branch' => 'release'];
    $this->postJson('/api/v1/instances', $payload)->assertAccepted();
    $instance = Instance::query()->sole();
    if ($change === 'layout') {
        $instance->update(['source_layout' => 'worktree']);
    } elseif ($change === 'root') {
        $payload['root'] = 'server/public';
    } elseif ($change === 'removed branch') {
        unset($payload['branch']);
    } else {
        $payload['branch'] = 'hotfix';
    }
    $before = $instance->getAttributes();

    $this->postJson('/api/v1/instances', $payload)->assertConflict()->assertJsonPath('error.code', 'instance.placement_conflict');
    expect($instance->refresh()->getAttributes())->toBe($before);
    expect(Operation::query()->count())->toBe(1)->and(Route::query()->count())->toBe(1);
})->with(['branch', 'removed branch', 'root', 'layout']);

it('refuses invalid or excluded placement before reserving an Instance', function (string $change, string $code): void {
    match ($change) {
        'node' => $this->node->update(['status' => 'failed']),
        'platform' => $this->node->update(['platform' => 'darwin']),
        'role' => $this->node->roles()->delete(),
        'production' => $this->node->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']),
        'excluded' => ProjectNodeExclusion::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id]),
    };

    $this->postJson('/api/v1/instances', $this->payload)->assertJsonPath('error.code', $code);
    expect(Instance::query()->count())->toBe(0)->and(Route::query()->count())->toBe(0)->and(Operation::query()->count())->toBe(0);
})->with([
    ['node', 'instance.node_inactive'], ['platform', 'instance.node_inactive'], ['role', 'instance.node_not_app_dev'],
    ['production', 'instance.candidate_required'], ['excluded', 'instance.node_excluded'],
]);

it('refuses occupied default checkout paths without reserving a second owner', function (bool $managed): void {
    if ($managed) {
        Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id,
            'name' => 'other', 'checkout_path' => '/srv/apps/admission/default', 'status' => 'active']);
    } else {
        $this->destinations->shouldReceive('assertUnoccupied')->once()
            ->andThrow(new ResourceOperationException('instance.migration_conflict', 'Occupied source.', 409));
    }

    $this->postJson('/api/v1/instances', [...$this->payload, 'name' => 'default'])->assertConflict()
        ->assertJsonPath('error.code', 'instance.default_path_occupied');
    expect(Instance::query()->where('name', 'default')->exists())->toBeFalse();
    expect(Route::query()->count())->toBe(0)->and(Operation::query()->count())->toBe(0);
})->with([true, false]);

it('keeps an admitted checkout immutable when the Node apps root changes', function (): void {
    $this->postJson('/api/v1/instances', $this->payload)->assertAccepted();
    $first = Instance::query()->sole();
    $this->node->update(['settings' => ['apps' => ['path' => '/mnt/apps']]]);

    $this->postJson('/api/v1/instances', [...$this->payload, 'name' => 'later'])->assertAccepted();
    expect($first->refresh()->checkout_path)->toBe('/srv/apps/admission/proof');
    expect(Instance::query()->where('name', 'later')->sole()->checkout_path)->toBe('/mnt/apps/admission/later');
});

it('keeps failed-create replay context after its proven cleanup deletes the Instance', function (): void {
    $id = $this->postJson('/api/v1/instances', $this->payload)->assertAccepted()->json('data.id');
    $parent = Operation::query()->findOrFail($id);
    $peer = Node::query()->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Gateway))->sole();
    config(['operation_executor.worker_node_ids' => [$peer->id]]);
    $executor = app(SwampOperationExecutor::class);
    $parent = $executor->claim('instance.create', 'failed-create-proof', $peer->id, $parent->id);
    $execution = app(InstanceLifecycleExecution::class);
    $descriptor = array_find($execution->context($parent)['descriptors'], static fn (array $entry): bool => $entry['key'] === 'source-resolve');
    $parent->update(['state' => [...($parent->state ?? []), 'lifecycle_bindings' => ['source-resolve' => $descriptor],
        'executor_runs' => ['source-resolve' => [...$descriptor, 'result' => ['error_code' => 'instance.git_failed'],
            'exit_code' => 1, 'finished_at' => now()->toISOString()]]]]);
    $input = ['instance_id' => $parent->target_id, 'force' => true];
    $intent = ['key' => 'rollback-removal', 'kind' => 'instance.destroy', 'node_id' => $parent->node_id, 'input' => $input];
    $snapshot = ['intent' => $intent, 'admitted' => ['parent_operation_id' => $parent->id, 'key' => $intent['key'],
        'kind' => $intent['kind'], 'node_id' => $parent->node_id, 'target_type' => 'instance', 'target_id' => $parent->target_id,
        'input' => $input, 'run_constraints' => []]];
    $child = Operation::query()->create(['kind' => 'instance.destroy', 'target_type' => 'instance', 'target_id' => $parent->target_id,
        'node_id' => $parent->node_id, 'input' => $input, 'status' => 'succeeded']);
    $child->forceFill(['parent_operation_id' => $parent->id,
        'child_key' => 'rollback-removal', 'child_admission' => $snapshot,
        'child_fingerprint' => OperationChildIntent::fingerprint($snapshot)])->save();
    Instance::query()->findOrFail($parent->target_id)->delete();

    $this->postJson('/api/v1/operation-executor/'.$parent->id.'/heartbeat', ['worker' => 'failed-create-proof'])->assertOk();
    $this->getJson('/api/v1/operations/'.$parent->id.'/instance-lifecycle')->assertOk();
    $this->getJson('/api/v1/operation-executor/'.$parent->id.'/receipts/source-resolve')->assertOk()
        ->assertJsonPath('data.state', 'failed')->assertJsonPath('data.result.error_code', 'instance.git_failed');
    $this->postJson('/api/v1/operation-executor/'.$parent->id.'/step', ['worker' => 'failed-create-proof',
        'key' => 'source-resolve:finish', 'name' => 'source-resolve', 'status' => 'failed',
        'error_code' => 'instance.git_failed', 'result' => ['error_code' => 'instance.git_failed']])->assertOk();
    $context = $execution->context($parent->refresh());
    expect(array_column($context['descriptors'], 'key'))->toContain('source-resolve');
    expect($context['descriptors'][0]['input'])->toBe($descriptor['input']);
    $this->postJson('/api/v1/operation-executor/'.$parent->id.'/complete', ['worker' => 'failed-create-proof',
        'status' => 'failed', 'result' => [], 'error_code' => 'instance.git_failed'])
        ->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error_code', 'instance.git_failed');
    expect($parent->refresh()->lock_key)->toBeNull();
});

it('admits the owning destroy child for a prepared create after native Git failure', function (): void {
    $id = $this->postJson('/api/v1/instances', $this->payload)->assertAccepted()->json('data.id');
    $parent = Operation::query()->findOrFail($id);
    $instance = Instance::query()->findOrFail($parent->target_id);
    $instance->update(['status' => 'checkout_prepared']);
    $source = Mockery::mock(DevelopmentInstanceSourceRemoval::class);
    $source->shouldReceive('inspect')->once()->andReturn(new InstanceSourceInventory(
        instanceId: $instance->id, layout: 'checkout', repositoryIdentity: $this->project->repository_identity,
        checkoutPath: $instance->checkout_path, root: 'public', branch: null, startingCommit: str_repeat('a', 40),
        commonRepositoryPath: $instance->checkout_path, sourceIdentity: 'prepared:1', linkedWorktreePaths: [$instance->checkout_path],
        digest: str_repeat('b', 64), origin: $this->project->repository_url));
    app()->instance(DevelopmentInstanceSourceRemoval::class, $source);
    $peer = Node::query()->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Gateway))->sole();
    config(['operation_executor.worker_node_ids' => [$peer->id]]);
    $executor = app(SwampOperationExecutor::class);
    $parent = $executor->claim('instance.create', 'failed-create-proof', $peer->id, $parent->id);
    $parent->update(['state' => [...($parent->state ?? []), 'executor_runs' => ['source-resolve' => [
        'result' => ['error_code' => 'instance.git_failed']]]]]);

    $child = $executor->child($parent, ['worker' => 'failed-create-proof', 'key' => 'rollback-removal'], $peer->id)['operation'];

    expect($child->kind)->toBe('instance.destroy')->and($child->input['force'])->toBeTrue();
    expect($child->parent_operation_id)->toBe($parent->id)->and($child->input['members'][0]['instance_id'])->toBe($instance->id)
        ->and($child->input['members'][0]['run_teardown'])->toBeFalse();
    $this->assertModelExists($instance);
});
