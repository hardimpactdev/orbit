<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceDestroyAction;
use App\Actions\Operations\CancelOperationAction;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\Environment\InstanceOperationPreflight;
use App\Domain\Instances\InstanceDestinationGuard;
use App\Domain\Instances\InstanceLifecycleExecution;
use App\Domain\Instances\InstanceRemovalState;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceRemoval;
use App\Domain\Instances\Removal\InstanceSourceInventory;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationChildIntent;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationSecretOutputs;
use App\Domain\Operations\SwampOperationExecutor;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\ProjectNodeExclusion;
use App\Models\Route;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeAgentCommandRunner;

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

it('admits and accepts prepared create cleanup with the same ownership guards', function (bool $worktree, bool $clustered, ?string $change = null): void {
    $id = $this->postJson('/api/v1/instances', $this->payload)->assertAccepted()->json('data.id');
    $parent = Operation::query()->findOrFail($id);
    $instance = Instance::query()->findOrFail($parent->target_id);
    $instance->update(['status' => 'checkout_prepared', 'source_layout' => $worktree ? 'worktree' : 'checkout',
        'seed_repository' => $worktree ? '/srv/apps/admission/default' : null,
        'seed_commit' => $worktree ? str_repeat('a', 40) : null]);
    $route = $instance->routes()->sole();
    if ($clustered) {
        $cluster = Cluster::query()->create(['name' => 'cleanup-cluster', 'tld' => 'test', 'state' => 'active']);
        $this->node->update(['cluster_id' => $cluster->id]);
    }
    if ($clustered) {
        $route->update(['node_id' => null, 'cluster_id' => $this->node->cluster_id, 'generation_basis_node_id' => $this->node->id]);
    }
    $source = Mockery::mock(DevelopmentInstanceSourceRemoval::class);
    $source->shouldReceive('inspect')->once()->andReturn(new InstanceSourceInventory(
        instanceId: $instance->id, layout: $worktree ? 'worktree' : 'checkout', repositoryIdentity: $this->project->repository_identity,
        checkoutPath: $instance->checkout_path, root: 'public', branch: null, startingCommit: str_repeat('a', 40),
        commonRepositoryPath: $worktree ? '/srv/apps/admission/default' : $instance->checkout_path, sourceIdentity: 'prepared:1', linkedWorktreePaths: [$instance->checkout_path],
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
    if ($change === 'cancel') {
        $child = $executor->claim('instance.destroy', 'cleanup-proof', $peer->id, $child->id);
        $descriptor = array_find(app(InstanceLifecycleExecution::class)->context($child)['descriptors'],
            static fn (array $entry): bool => $entry['key'] === 'removal-accept');
        $executor->step($child, ['worker' => 'cleanup-proof', 'key' => 'removal-accept:start',
            'name' => 'removal-accept', 'status' => 'running', 'result' => []], $peer->id);
        $child->update(['state' => [...$child->state, 'executor_runs' => ['removal-accept' => [
            ...$descriptor, 'started' => true, 'result' => null]]]]);
        app(CancelOperationAction::class)->execute($parent);
        expect(fn () => app(InstanceRemovalState::class)->transition($child, ['action' => 'removal-accept']))
            ->toThrow(ResourceOperationException::class, 'Removal was cancelled before acceptance.');
        $executor->step($child, ['worker' => 'cleanup-proof', 'key' => 'removal-accept:finish',
            'name' => 'removal-accept', 'status' => 'failed', 'result' => ['error_code' => 'operation.cancelled'],
            'error_code' => 'operation.cancelled'], $peer->id);
        $cancelled = ['status' => 'cancelled', 'result' => [], 'error_code' => 'operation.cancelled'];
        expect($executor->complete($child, ['worker' => 'cleanup-proof', ...$cancelled], $peer->id)->status->value)->toBe('cancelled');
        expect($executor->complete($parent, ['worker' => 'failed-create-proof', ...$cancelled], $peer->id)->status->value)->toBe('cancelled');
        expect(InstanceRemoval::query()->count())->toBe(0);
        expect($instance->refresh()->status->value)->toBe('checkout_prepared');

        return;
    }
    if ($change !== null) {
        $instance->update([$change => $change === 'registration_request_id' ? 'unrelated-registration' : null]);
        expect(fn () => app(InstanceRemovalState::class)->transition($child, ['action' => 'removal-accept']))
            ->toThrow(QueryException::class, 'Invalid Instance removal contract.');
        expect(InstanceRemoval::query()->count())->toBe(0);
        expect($instance->refresh()->status->value)->toBe('checkout_prepared');

        return;
    }
    expect(app(InstanceRemovalState::class)->transition($child, ['action' => 'removal-accept']))
        ->toBe(['accepted' => true, 'removal_id' => $child->input['removal_id']]);
    expect($instance->refresh()->status->value)->toBe('removing');
})->with(['checkout / direct Route' => [false, false], 'checkout / clustered Route' => [false, true],
    'worktree / direct Route' => [true, false], 'worktree / clustered Route' => [true, true],
    'missing prepare identity' => [true, false, 'source_prepare_id'],
    'missing seed repository' => [true, false, 'seed_repository'],
    'missing seed commit' => [true, false, 'seed_commit'],
    'registration attempt' => [true, false, 'registration_request_id'],
    'cancel before acceptance' => [true, false, 'cancel']]);

it('refuses prepared worktree cleanup when its pending Route belongs to another cluster', function (): void {
    $id = $this->postJson('/api/v1/instances', $this->payload)->assertAccepted()->json('data.id');
    $instance = Instance::query()->findOrFail(Operation::query()->findOrFail($id)->target_id);
    $instance->update(['status' => 'checkout_prepared', 'source_layout' => 'worktree',
        'seed_repository' => '/srv/apps/admission/default', 'seed_commit' => str_repeat('a', 40)]);
    $route = $instance->routes()->sole();
    $cluster = Cluster::query()->create(['name' => 'foreign-cluster', 'tld' => 'foreign', 'state' => 'active']);
    $route->update(['node_id' => null, 'cluster_id' => $cluster->id]);
    $source = Mockery::mock(DevelopmentInstanceSourceRemoval::class);
    $source->shouldReceive('inspect')->once()->andReturn(new InstanceSourceInventory(
        instanceId: $instance->id, layout: 'worktree', repositoryIdentity: $this->project->repository_identity,
        checkoutPath: $instance->checkout_path, root: 'public', branch: null, startingCommit: str_repeat('a', 40),
        commonRepositoryPath: '/srv/apps/admission/default', sourceIdentity: 'prepared:1', linkedWorktreePaths: [$instance->checkout_path],
        digest: str_repeat('b', 64), origin: $this->project->repository_url));
    app()->instance(DevelopmentInstanceSourceRemoval::class, $source);

    expect(fn () => app(StartInstanceDestroyAction::class)->snapshot($instance, true))
        ->toThrow(ResourceOperationException::class);
    $this->assertModelExists($instance);
    $this->assertModelExists($route);
    expect(Operation::query()->where('kind', 'instance.destroy')->exists())->toBeFalse();
});

it('synchronizes the resolved create source through its owning child before activation', function (): void {
    $this->project->update(['type' => 'symfony-app']);
    $id = $this->postJson('/api/v1/instances', $this->payload)->assertAccepted()->json('data.id');
    $parent = Operation::query()->findOrFail($id);
    $instance = Instance::query()->findOrFail($parent->target_id);
    $instance->update(['status' => 'source_resolved', 'provisioning_step' => 'php-selected',
        'branch' => 'proof', 'starting_commit' => str_repeat('a', 40), 'source_is_laravel' => false, 'selected_php_version' => '8.5']);
    $peer = Node::query()->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Gateway))->sole();
    config(['operation_executor.worker_node_ids' => [$peer->id]]);
    $executor = app(SwampOperationExecutor::class);
    $parent = $executor->claim('instance.create', 'create-env-proof', $peer->id, $parent->id);
    $descriptor = array_find(app(InstanceLifecycleExecution::class)->context($parent)['descriptors'],
        static fn (array $entry): bool => $entry['key'] === 'source-configuration');
    $profile = array_find(app(InstanceLifecycleExecution::class)->context($parent)['descriptors'],
        static fn (array $entry): bool => $entry['key'] === 'source-profile');
    $parent->update(['state' => [...$parent->state, 'lifecycle_bindings' => ['source-profile' => $profile],
        'executor_runs' => ['source-profile' => [...$profile, 'result' => ['php_version' => '8.5', 'laravel' => false]]]]]);
    $parent->steps()->create(['name' => 'source-profile', 'position' => 1, 'status' => 'succeeded', 'sequence' => 1, 'resumable' => true]);
    $agents = new FakeAgentCommandRunner;
    $agents->answer('instance.read-development-environment-template', ['environment_template' => "API_TOKEN=synthetic-create-secret\nAPP_SECRET=\n"]);
    app()->instance(AgentCommandRunner::class, $agents);
    $path = '/api/v1/operation-executor/'.$parent->id;
    $this->postJson($path.'/run', ['worker' => 'create-env-proof', 'key' => $descriptor['key'],
        'transport' => $descriptor['transport'], 'name' => $descriptor['name'],
        'input' => [...$descriptor['input'], 'commit' => str_repeat('b', 40)]])->assertUnprocessable()
        ->assertJsonPath('error.code', 'operation.execution_forbidden');
    expect($agents->calls)->toBe([]);
    $result = $this->postJson($path.'/run', ['worker' => 'create-env-proof',
        ...array_intersect_key($descriptor, array_flip(['key', 'transport', 'name', 'input']))])->assertOk()->json('data');
    $this->postJson($path.'/step', ['worker' => 'create-env-proof', 'key' => 'source-configuration:finish',
        'name' => 'source-configuration', 'status' => 'succeeded', 'result' => $result])->assertOk();
    expect($result)->toBe(['written' => true]);
    $stored = app(InstanceEnvironmentStore::class)->values($instance);
    expect($stored)->toMatchArray(['API_TOKEN' => 'synthetic-create-secret', 'APP_URL' => 'https://{{instance.domain}}',
        'APP_NAME' => 'Admission', 'APP_ENV' => 'dev', 'APP_DEBUG' => 'true']);
    expect($stored['APP_SECRET'])->toHaveLength(32);
    $this->postJson($path.'/run', ['worker' => 'create-env-proof',
        ...array_intersect_key($descriptor, array_flip(['key', 'transport', 'name', 'input']))])->assertOk()->assertJsonPath('data.written', true);
    expect(app(InstanceEnvironmentStore::class)->values($instance))->toBe($stored);
    $childId = $this->postJson($path.'/children', ['worker' => 'create-env-proof', 'key' => 'environment-sync'])
        ->assertAccepted()->assertJsonPath('data.kind', 'instance.sync_environment')->json('data.id');
    $this->postJson($path.'/children', ['worker' => 'create-env-proof', 'key' => 'environment-sync'])
        ->assertOk()->assertJsonPath('data.id', $childId);
    $this->postJson($path.'/wait', ['worker' => 'create-env-proof', 'keys' => ['environment-sync']])->assertOk();
    $preflight = Mockery::mock(InstanceOperationPreflight::class);
    $preflight->shouldReceive('readWritableEnvironment')->once()->andThrow(
        new ResourceOperationException('env.import_source_missing', 'The new workload has no environment file.', 409));
    app()->instance(InstanceOperationPreflight::class, $preflight);
    $agents->answer('instance.write-environment', ['state' => 'changed', 'installed' => true]);
    app()->instance(AgentCommandRunner::class, $agents);
    $path = '/api/v1/operation-executor/'.$childId;
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'instance.sync_environment', 'worker' => 'create-env-child'])
        ->assertOk()->assertJsonPath('data.id', $childId);
    foreach (['environment.prepare', 'environment.write', 'environment.receipt'] as $key) {
        $descriptors = $this->postJson($path.'/heartbeat', ['worker' => 'create-env-child'])
            ->assertOk()->json('data.execution_context.descriptors');
        $descriptor = array_find($descriptors, static fn (array $entry): bool => $entry['key'] === $key);
        $result = $this->postJson($path.'/run', ['worker' => 'create-env-child',
            ...array_intersect_key($descriptor, array_flip(['key', 'transport', 'name', 'input']))])->assertOk()->json('data');
        $this->postJson($path.'/step', ['worker' => 'create-env-child', 'key' => $key.':finish',
            'name' => $descriptor['step'], 'status' => 'succeeded', 'result' => $result])->assertOk();
    }
    $this->postJson($path.'/complete', ['worker' => 'create-env-child', 'status' => 'succeeded', 'result' => $result])
        ->assertOk()->assertJsonPath('data.status', 'succeeded');
    $child = Operation::query()->findOrFail($childId);
    expect($child->parent_operation_id)->toBe($parent->id)->and($child->child_key)->toBe('environment-sync');
    expect($agents->commands())->toBe(['instance.read-development-environment-template', 'instance.write-environment']);
    expect($agents->calls[1]['input']['content'])->toContain('API_TOKEN="synthetic-create-secret"', 'APP_URL="https://proof.admission.test"');
    expect($instance->refresh()->status->value)->toBe('source_resolved');
    expect($instance->environment_owned_keys)->toBe(['API_TOKEN', 'APP_DEBUG', 'APP_ENV', 'APP_NAME', 'APP_SECRET', 'APP_URL']);
    expect(json_encode([$parent->refresh()->toArray(), $child->toArray()]))->not->toContain('synthetic-create-secret', $stored['APP_SECRET']);
    expect(fn () => app(InstanceEnvironmentContextResolver::class)->resolve($instance, true))
        ->toThrow(ResourceOperationException::class, 'The Instance environment owner is not available');
});

it('seeds absent create templates and keeps stored application keys on replay', function (string $type, string $key, bool $existing): void {
    $this->project->update(['type' => $type]);
    $id = $this->postJson('/api/v1/instances', $this->payload)->assertAccepted()->json('data.id');
    $operation = Operation::query()->findOrFail($id);
    $operation->update(['status' => 'running']);
    $instance = Instance::query()->findOrFail($operation->target_id);
    $instance->update(['status' => 'source_resolved', 'provisioning_step' => 'php-selected',
        'source_is_laravel' => $type === 'laravel-app', 'selected_php_version' => '8.5']);
    if ($existing) {
        $instance->environmentValues()->create(['env_key' => $key, 'env_value' => 'synthetic-preserved-key']);
        $instance->environmentValues()->create(['env_key' => 'APP_NAME', 'env_value' => 'Stored name']);
    }
    $outputs = app(OperationSecretOutputs::class);

    expect($outputs->extract($operation, ['environment_template' => ''])['result'])
        ->toBe(['environment_template' => ['$secret' => 'instance.create_environment', 'id' => $instance->id]]);
    $stored = app(InstanceEnvironmentStore::class)->values($instance);
    expect($stored)->toMatchArray(['APP_NAME' => $existing ? 'Stored name' : 'Admission',
        'APP_ENV' => $type === 'laravel-app' ? 'development' : 'dev', 'APP_URL' => 'https://{{instance.domain}}']);
    if ($existing) {
        expect($stored[$key])->toBe('synthetic-preserved-key');
    } else {
        expect($stored[$key])->toHaveLength($type === 'laravel-app' ? 51 : 32);
    }
    $outputs->extract($operation, ['environment_template' => $key."=replacement\nAPP_NAME=replacement\n"]);
    expect(app(InstanceEnvironmentStore::class)->values($instance))->toBe($stored);
})->with([['laravel-app', 'APP_KEY'], ['symfony-app', 'APP_SECRET']])->with([false, true]);

it('refuses an invalid create template without storing a partial configuration', function (): void {
    $id = $this->postJson('/api/v1/instances', $this->payload)->assertAccepted()->json('data.id');
    $operation = Operation::query()->findOrFail($id);
    $operation->update(['status' => 'running']);
    $instance = Instance::query()->findOrFail($operation->target_id);
    $instance->update(['status' => 'source_resolved', 'provisioning_step' => 'php-selected', 'source_is_laravel' => true]);

    expect(fn () => app(OperationSecretOutputs::class)->extract($operation,
        ['environment_template' => "APP_NAME=first\nAPP_NAME=second\n"]))->toThrow(ResourceOperationException::class);
    expect($instance->environmentValues()->exists())->toBeFalse();
});
