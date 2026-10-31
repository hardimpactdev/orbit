<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceRemoval;
use App\Domain\Instances\Removal\InstanceSourceInventory;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationKinds;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Project;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    config(['swamp.run_token' => 'fixture.shared.token']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-instance-destroy', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    app()->instance(WebSocketClient::class, $socket);
    app()->instance(OperationKinds::class, new OperationKinds(app(), [
        ...OperationKinds::definitions(), ...require app_path('Domain/Instances/lifecycle-operation-kinds.php'),
    ]));
    $accounts = Mockery::mock(ManagedUserAccountResolver::class);
    $accounts->shouldReceive('resolve')->andReturn(new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));
    app()->instance(ManagedUserAccountResolver::class, $accounts);
    $this->node = Node::query()->create(['name' => 'dev', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'amd64', 'user' => 'orbit',
        'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9']);
    NodeRole::query()->create(['node_id' => $this->node->id, 'role' => RoleName::AppDev, 'status' => 'active']);
    $this->node->accessibleNodes()->attach($this->node);
    $project = Project::query()->create(['name' => 'Removal proof', 'slug' => 'removal-proof', 'type' => 'monorepo',
        'repository_url' => 'https://example.test/removal-proof.git', 'root' => 'public', 'default_branch' => 'main']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id,
        'name' => 'isolated', 'status' => InstanceState::Active, 'checkout_path' => '/srv/apps/removal-proof/isolated',
        'source_layout' => 'checkout', 'branch' => 'isolated', 'starting_commit' => str_repeat('a', 40)]);
    $this->inventory = fn (array $paths = []): InstanceSourceInventory => new InstanceSourceInventory(
        instanceId: $this->instance->id, layout: 'checkout', repositoryIdentity: $project->repository_identity,
        checkoutPath: $this->instance->checkout_path, root: 'public', branch: 'isolated', startingCommit: str_repeat('b', 40),
        commonRepositoryPath: $this->instance->checkout_path, sourceIdentity: '1:1',
        linkedWorktreePaths: $paths === [] ? [$this->instance->checkout_path] : $paths, digest: str_repeat('c', 64),
        origin: $project->repository_url,
    );
    $this->source = Mockery::mock(DevelopmentInstanceSourceRemoval::class);
    $this->source->shouldNotReceive('remove');
    app()->instance(DevelopmentInstanceSourceRemoval::class, $this->source);
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $runner);
    $this->path = '/api/v1/instances/'.$this->instance->id;
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    Queue::fake();
});

it('admits a fixed source set without executing teardown, deleting source or accepting the removal ledger', function (bool $force): void {
    $this->source->shouldReceive('inspect')->times($force ? 1 : 2)->andReturn(($this->inventory)());
    $this->withHeader('Idempotency-Key', 'destroy-proof');
    $id = $this->deleteJson($this->path, ['force' => $force])->assertAccepted()
        ->assertJsonPath('data.kind', 'instance.destroy_development')->assertJsonPath('data.status', 'queued')->json('data.id');
    $this->deleteJson($this->path, ['force' => $force])->assertAccepted()->assertJsonPath('data.id', $id);
    $operation = Operation::query()->findOrFail($id);
    expect($operation->node_id)->toBe($this->node->id)->and($operation->target_id)->toBe($this->instance->id)
        ->and($operation->input['force'])->toBe($force)
        ->and($operation->input['members'][0]['starting_commit'])->toBe(str_repeat('a', 40))
        ->and($operation->input['members'][0]['source_commit'])->toBe(str_repeat('b', 40))
        ->and($this->instance->refresh()->status)->toBe(InstanceState::Removing)
        ->and(InstanceRemoval::query()->count())->toBe(1);
    Queue::assertNothingPushed();
})->with([false, true]);

it('admits executor Node removal through the same parent without running a Gateway remover', function (string $executor): void {
    config($executor === 'services'
        ? ['dependency_detection.services_node_id' => $this->node->id]
        : ['operation_executor.worker_node_ids' => [$this->node->id]]);
    $process = Process::query()->create(['owner_type' => Instance::MorphAlias, 'owner_id' => $this->instance->id,
        'name' => 'ssr', 'runtime' => 'systemd', 'working_directory' => $this->instance->checkout_path,
        'runtime_config' => ['command' => ['vp', 'dev']], 'restart_policy' => 'always',
        'desired_state' => 'running', 'status' => 'active']);
    $this->source->shouldReceive('inspect')->once()->andReturn(($this->inventory)());
    $runtime = Mockery::mock(ProcessRuntimeManager::class);
    $runtime->shouldNotReceive('remove');
    app()->instance(ProcessRuntimeManager::class, $runtime);

    $operationId = $this->deleteJson($this->path, ['force' => true])->assertAccepted()
        ->assertJsonPath('data.kind', 'instance.destroy_development')
        ->assertJsonPath('data.status', 'queued')->json('data.id');

    $this->assertModelExists($this->instance);
    $this->assertModelExists($process);
    $this->assertDatabaseCount('operations', 1);
    $operation = Operation::query()->findOrFail($operationId);
    expect($operation->input['force'])->toBeTrue()
        ->and($operation->node_id)->toBe($this->node->id)
        ->and($operation->target_id)->toBe($this->instance->id)
        ->and(InstanceRemoval::query()->count())->toBe(1);
    Queue::assertNothingPushed();
})->with(['services', 'worker']);

it('replays the receipt after row deletion with the original Node access and request fingerprint', function (): void {
    $this->source->shouldReceive('inspect')->once()->andReturn(($this->inventory)());
    $this->withHeader('Idempotency-Key', 'lost-destroy-reply');
    $id = $this->deleteJson($this->path, ['force' => true])->assertAccepted()->json('data.id');
    $this->instance->delete();
    $this->deleteJson($this->path, ['force' => true])->assertAccepted()->assertJsonPath('data.id', $id);
    $this->deleteJson($this->path, ['force' => false])->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');
    $outsider = Node::query()->create(['name' => 'other', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'amd64', 'user' => 'orbit',
        'public_ssh_host' => '192.0.2.18', 'wireguard_ip' => '10.44.0.18']);
    $this->withServerVariables(['REMOTE_ADDR' => $outsider->wireguard_ip]);
    $this->deleteJson($this->path, ['force' => true])->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
    Queue::assertNothingPushed();
});

it('does not accept an unrelated key for a deleted target', function (): void {
    $this->source->shouldNotReceive('inspect');
    $this->instance->delete();
    $this->withHeader('Idempotency-Key', 'unrelated');
    $this->deleteJson($this->path, ['force' => true])->assertNotFound();
    expect(Operation::query()->count())->toBe(0);
});

it('preserves source refusal codes and state before admission in either mode', function (bool $force): void {
    $this->source->shouldReceive('inspect')->once()->andThrow(new RuntimeConvergenceException(
        'source-inspection', 'instance.source_branch_mismatch', 'The source branch differs from the recorded branch.',
    ));
    $this->deleteJson($this->path, ['force' => $force])->assertConflict()->assertJsonPath('error.code', 'instance.source_branch_mismatch');
    expect($this->instance->refresh()->status)->toBe(InstanceState::Active)
        ->and(Operation::query()->count())->toBe(0)->and(InstanceRemoval::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([false, true]);

it('refuses unregistered linked source even when force is requested', function (bool $force): void {
    $this->source->shouldReceive('inspect')->once()->andReturn(($this->inventory)([$this->instance->checkout_path, '/srv/apps/removal-proof/unregistered']));
    $this->deleteJson($this->path, ['force' => $force])->assertConflict()->assertJsonPath('error.code', 'instance.remove_refused');
    expect($this->instance->refresh()->status)->toBe(InstanceState::Active)->and(Operation::query()->count())->toBe(0);
})->with([false, true]);

it('validates strict force input before inspecting source', function (): void {
    $this->source->shouldNotReceive('inspect');
    $this->deleteJson($this->path, ['force' => 'true'])->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    expect(Operation::query()->count())->toBe(0);
});

it('admits native removal without the retired teardown child registration', function (): void {
    $definitions = [...OperationKinds::definitions(), ...require app_path('Domain/Instances/lifecycle-operation-kinds.php')];
    unset($definitions['instance.run_steps']);
    app()->instance(OperationKinds::class, new OperationKinds(app(), $definitions));
    $this->source->shouldReceive('inspect')->once()->andReturn(($this->inventory)());

    $this->deleteJson($this->path, ['force' => true])->assertAccepted()->assertJsonPath('data.kind', 'instance.destroy_development');

    $this->assertDatabaseCount('operations', 1);
    $this->assertDatabaseCount('instance_removals', 1);
    $this->assertDatabaseHas('instances', ['id' => $this->instance->id, 'status' => 'removing']);
    Queue::assertNothingPushed();
});

it('admits a registered worktree set only with force and records worktrees before the checkout', function (bool $force): void {
    $worktree = Instance::query()->create(['project_id' => $this->instance->project_id, 'node_id' => $this->node->id,
        'name' => 'linked', 'status' => InstanceState::Active, 'checkout_path' => '/srv/apps/removal-proof/linked',
        'source_layout' => 'worktree', 'branch' => 'linked', 'starting_commit' => str_repeat('a', 40)]);
    $paths = [$this->instance->checkout_path, $worktree->checkout_path];
    $root = ($this->inventory)($paths);
    $linked = new InstanceSourceInventory(instanceId: $worktree->id, layout: 'worktree', repositoryIdentity: $root->repositoryIdentity,
        checkoutPath: $worktree->checkout_path, root: 'public', branch: 'linked', startingCommit: str_repeat('b', 40),
        commonRepositoryPath: $this->instance->checkout_path, sourceIdentity: 'proof:2', linkedWorktreePaths: $paths,
        digest: str_repeat('d', 64));
    $this->source->shouldReceive('inspect')->times($force ? 2 : 1)
        ->andReturnUsing(fn (Instance $instance): InstanceSourceInventory => $instance->id === $worktree->id ? $linked : $root);
    $response = $this->deleteJson($this->path, ['force' => $force]);
    if ($force) {
        $operation = Operation::query()->findOrFail($response->assertAccepted()->json('data.id'));
        expect(array_column($operation->input['members'], 'instance_id'))->toBe([$worktree->id, $this->instance->id]);
    } else {
        $response->assertConflict()->assertJsonPath('error.code', 'instance.remove_refused');
        expect(Operation::query()->count())->toBe(0);
    }
    expect($this->instance->refresh()->status)->toBe($force ? InstanceState::Removing : InstanceState::Active)
        ->and($worktree->refresh()->status)->toBe($force ? InstanceState::Removing : InstanceState::Active)
        ->and(InstanceRemoval::query()->count())->toBe($force ? 1 : 0);
})->with([false, true]);


it('keeps managed development removal on its complete working admission without a direct SQL bypass', function (): void {
    $server = \App\Models\DatabaseServer::query()->create(['slug' => 'dispatch-managed', 'node_id' => $this->node->id,
        'driver' => 'mysql', 'tag' => 'test', 'port' => 3306, 'root_password' => 'fixture-private-password', 'status' => 'active']);
    \App\Models\DatabaseConnection::query()->create(['slug' => 'dispatch-managed', 'driver' => 'mysql',
        'database_server_id' => $server->id, 'owner_instance_id' => $this->instance->id]);
    $this->source->shouldReceive('inspect')->once()->andReturn(($this->inventory)());
    $this->withHeader('Idempotency-Key', 'managed-destroy');

    $id = $this->deleteJson($this->path, ['force' => true])->assertAccepted()
        ->assertJsonPath('data.kind', 'instance.destroy')->json('data.id');
    $this->deleteJson($this->path, ['force' => true])->assertAccepted()->assertJsonPath('data.id', $id);
    expect(app(OperationKinds::class)->directOnly('instance.destroy'))->toBeFalse();
    expect($this->instance->refresh()->status)->toBe(InstanceState::Active);
    expect(InstanceRemoval::query()->count())->toBe(0);
});
