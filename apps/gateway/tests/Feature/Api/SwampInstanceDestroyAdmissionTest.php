<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceDestroyPhpSecret;
use App\Domain\Instances\InstanceDestroyProjection;
use App\Domain\Instances\InstanceDestroySourceInspection;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceRemoval;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Project;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    config(['swamp.run_token' => 'fixture.shared.token']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-instance-destroy', 'runId' => (string) Str::uuid()]]];
    app()->instance(WebSocketClient::class, $socket);
    $this->socket = $socket;
    $accounts = Mockery::mock(ManagedUserAccountResolver::class);
    $accounts->shouldNotReceive('resolve');
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
    $this->source = Mockery::mock(DevelopmentInstanceSourceRemoval::class);
    $this->source->shouldNotReceive('remove', 'inspect');
    app()->instance(DevelopmentInstanceSourceRemoval::class, $this->source);
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $runner);
    $this->path = '/api/v1/instances/'.$this->instance->id;
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    Queue::fake();
});

it('admits stored source intent and one native root without Gateway source or account calls', function (bool $force): void {
    $this->withHeader('Idempotency-Key', 'destroy-proof');
    $id = $this->deleteJson($this->path, ['force' => $force])->assertAccepted()
        ->assertJsonPath('data.kind', 'instance.destroy')->assertJsonPath('data.status', 'running')->json('data.id');
    $this->deleteJson($this->path, ['force' => $force])->assertAccepted()->assertJsonPath('data.id', $id);
    $operation = Operation::query()->findOrFail($id);
    expect($operation->node_id)->toBe($this->node->id)->and($operation->target_id)->toBe($this->instance->id)
        ->and($operation->input['force'])->toBe($force)
        ->and($operation->input['members'][0]['starting_commit'])->toBe(str_repeat('a', 40))
        ->and($operation->input['members'][0]['source_commit'])->toBe(str_repeat('a', 40))
        ->and($operation->input['source_pending'])->toBeTrue()
        ->and($operation->input['members'][0]['origin'])->toBeNull()
        ->and($operation->input['members'][0]['worktree_inventory'])->toBeNull()
        ->and($this->socket->sent)->toHaveCount(1)
        ->and($this->socket->sent[0]['type'])->toBe('workflow.run')
        ->and($this->socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-instance-destroy')
        ->and($this->instance->refresh()->status)->toBe(InstanceState::Removing)
        ->and(InstanceRemoval::query()->count())->toBe(1);
    Queue::assertNothingPushed();
})->with([false, true]);

it('admits the same native root when retired executor settings name its Node', function (string $executor): void {
    config($executor === 'services'
        ? ['dependency_detection.services_node_id' => $this->node->id]
        : ['operation_executor.worker_node_ids' => [$this->node->id]]);
    $process = Process::query()->create(['owner_type' => Instance::MorphAlias, 'owner_id' => $this->instance->id,
        'name' => 'ssr', 'runtime' => 'systemd', 'working_directory' => $this->instance->checkout_path,
        'runtime_config' => ['command' => ['vp', 'dev']], 'restart_policy' => 'always',
        'desired_state' => 'running', 'status' => 'active']);
    $runtime = Mockery::mock(ProcessRuntimeManager::class);
    $runtime->shouldNotReceive('remove');
    app()->instance(ProcessRuntimeManager::class, $runtime);

    $operationId = $this->deleteJson($this->path, ['force' => true])->assertAccepted()
        ->assertJsonPath('data.kind', 'instance.destroy')
        ->assertJsonPath('data.status', 'running')->json('data.id');

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

it('records native source refusals after admission without deleting the Instance', function (bool $force): void {
    $id = $this->deleteJson($this->path, ['force' => $force])->assertAccepted()->json('data.id');
    $operation = Operation::query()->findOrFail($id);
    app(InstanceDestroyProjection::class)->apply($operation, [
        'run_id' => $operation->state['swamp_run_id'], 'status' => 'failed',
        'started_at' => '2026-10-31T04:00:00Z', 'finished_at' => '2026-10-31T04:00:01Z',
        'result' => ['error_code' => 'instance.source_branch_mismatch', 'failed_step' => 'capture_sources'],
        'error_code' => 'instance.source_branch_mismatch']);

    expect($operation->refresh()->status->value)->toBe('failed')
        ->and($operation->error_code)->toBe('instance.source_branch_mismatch')
        ->and($this->instance->refresh()->status)->toBe(InstanceState::Removing)
        ->and(InstanceRemoval::query()->sole()->native_sources)->toBeNull();
    Queue::assertNothingPushed();
})->with([false, true]);

it('validates strict force input before inspecting source', function (): void {
    $this->source->shouldNotReceive('inspect');
    $this->deleteJson($this->path, ['force' => 'true'])->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    expect(Operation::query()->count())->toBe(0);
});

it('records the owning database snapshot before marking the Instance Removing', function (): void {
    $database = DatabaseConnection::query()->create(['slug' => 'native-owned', 'driver' => 'sqlite',
        'node_id' => $this->node->id, 'owner_instance_id' => $this->instance->id,
        'path' => $this->instance->checkout_path.'/database.sqlite']);
    $id = $this->deleteJson($this->path, ['force' => true])->assertAccepted()->json('data.id');
    $operation = Operation::query()->findOrFail($id);

    expect($operation->input['source_pending'])->toBeTrue()
        ->and($operation->input['direct_destroy']['database']['connection_ids'][0])->toBe($database->id)
        ->and($operation->input['direct_destroy']['teardown'][0]['user'])->toBe('orbit')
        ->and($this->instance->refresh()->status)->toBe(InstanceState::Removing);
    expect(fn () => $database->update(['path' => $this->instance->checkout_path.'/replacement.sqlite']))
        ->toThrow(ResourceOperationException::class);
    $this->assertDatabaseCount('operations', 1);
});

it('admits only the requested member without discovering or deleting sibling worktrees in Gateway', function (bool $force): void {
    $worktree = Instance::query()->create(['project_id' => $this->instance->project_id, 'node_id' => $this->node->id,
        'name' => 'linked', 'status' => InstanceState::Active, 'checkout_path' => '/srv/apps/removal-proof/linked',
        'source_layout' => 'worktree', 'branch' => 'linked', 'starting_commit' => str_repeat('a', 40)]);
    $id = $this->deleteJson($this->path, ['force' => $force])->assertAccepted()->json('data.id');
    $operation = Operation::query()->findOrFail($id);

    expect(array_column($operation->input['members'], 'instance_id'))->toBe([$this->instance->id])
        ->and($operation->input['source_pending'])->toBeTrue()
        ->and($this->instance->refresh()->status)->toBe(InstanceState::Removing)
        ->and($worktree->refresh()->status)->toBe(InstanceState::Active)
        ->and(InstanceRemoval::query()->sole()->members()->count())->toBe(1);
})->with([false, true]);

it('admits managed SQL in the same native parent without source reads or child Operations', function (): void {
    $server = DatabaseServer::query()->create(['slug' => 'dispatch-managed', 'node_id' => $this->node->id,
        'driver' => 'mysql', 'tag' => 'test', 'port' => 3306, 'root_password' => 'fixture-private-password', 'status' => 'active']);
    DatabaseConnection::query()->create(['slug' => 'dispatch-managed', 'driver' => 'mysql',
        'database_server_id' => $server->id, 'owner_instance_id' => $this->instance->id]);
    $this->source->shouldNotReceive('inspect');
    $id = $this->deleteJson($this->path, ['force' => true])->assertAccepted()->json('data.id');
    $operation = Operation::query()->findOrFail($id);
    expect($operation->input['direct_destroy']['database']['servers'][0]['server_id'])->toBe($server->id)
        ->and($operation->input['database_material']['$secret'])->toBe('database.intent')
        ->and(json_encode($operation->input))->not->toContain('fixture-private-password')
        ->and(Operation::query()->count())->toBe(1)
        ->and($this->instance->refresh()->status)->toBe(InstanceState::Removing)
        ->and(InstanceRemoval::query()->count())->toBe(1);
});

it('refuses a second native root with a new key while the accepted removal is queued or running', function (): void {
    $this->withHeader('Idempotency-Key', 'first-root');
    $id = $this->deleteJson($this->path, ['force' => true])->assertAccepted()->json('data.id');
    $this->deleteJson($this->path, ['force' => true])->assertAccepted()->assertJsonPath('data.id', $id);
    $this->withHeader('Idempotency-Key', 'second-root');
    $this->deleteJson($this->path, ['force' => true])->assertConflict()->assertJsonPath('error.code', 'operation.target_busy');
    Operation::query()->findOrFail($id)->update(['status' => 'running']);
    $this->withHeader('Idempotency-Key', 'third-root');
    $this->deleteJson($this->path, ['force' => true])->assertConflict()->assertJsonPath('error.code', 'operation.target_busy');
    expect(Operation::query()->count())->toBe(1);
    expect(InstanceRemoval::query()->count())->toBe(1);
});

it('admits a new native retry after terminal failure using the same immutable source and database admission', function (): void {
    $database = DatabaseConnection::query()->create(['slug' => 'retry-owned', 'driver' => 'sqlite',
        'node_id' => $this->node->id, 'owner_instance_id' => $this->instance->id,
        'path' => $this->instance->checkout_path.'/database.sqlite']);
    $this->withHeader('Idempotency-Key', 'failed-root');
    $id = $this->deleteJson($this->path, ['force' => true])->assertAccepted()->json('data.id');
    $first = Operation::query()->findOrFail($id);
    $saved = $first->input;
    $captured = ['members' => [['instance_id' => $this->instance->id, 'user' => 'orbit', 'group' => 'apps',
        'source_root' => '/srv/apps', 'source_digest' => str_repeat('c', 64), 'source_commit' => str_repeat('b', 40),
        'common_repository_path' => $this->instance->checkout_path, 'source_identity' => '42:73',
        'linked_worktree_paths' => [$this->instance->checkout_path],
        'worktree_inventory' => base64_encode('worktree '.$this->instance->checkout_path."\0\0"),
        'origin' => $this->instance->project->repository_url]], 'inventory_digest' => str_repeat('d', 64)];
    app(InstanceDestroyProjection::class)->apply($first, [
        'run_id' => $first->state['swamp_run_id'], 'status' => 'running', 'started_at' => '2026-10-31T04:00:00Z',
        'result' => ['source_inspection' => $captured], 'error_code' => null]);
    app(InstanceDestroyProjection::class)->apply($first, [
        'run_id' => $first->state['swamp_run_id'], 'status' => 'failed', 'started_at' => '2026-10-31T04:00:00Z',
        'finished_at' => '2026-10-31T04:00:01Z', 'result' => ['error_code' => 'instance.destroy_failed', 'failed_step' => 'databases'],
        'error_code' => 'instance.destroy_failed']);
    $this->instance->project->update(['repository_url' => 'https://example.test/changed-after-failure.git']);
    $this->withHeader('Idempotency-Key', 'retry-root');

    $next = $this->deleteJson($this->path, ['force' => true])->assertAccepted()
        ->assertJsonPath('data.kind', 'instance.destroy')->json('data.id');
    expect($next)->not->toBe($id);
    $retried = Operation::query()->findOrFail($next);
    expect($retried->input)->toBe($saved)
        ->and($retried->state['swamp_run_id'])->not->toBe($first->state['swamp_run_id'])
        ->and($retried->input['direct_destroy']['database']['connection_ids'][0])->toBe($database->id)
        ->and($this->socket->sent)->toHaveCount(2);
    expect(Operation::query()->count())->toBe(2);
    $executable = app(InstanceDestroySourceInspection::class)->executable(Operation::query()->findOrFail($next)->input);
    expect($executable['source_pending'])->toBeFalse()
        ->and($executable['direct_destroy']['members'][0]['origin'])->toBe($captured['members'][0]['origin'])
        ->and($executable['direct_destroy']['members'][0]['source']['source_identity'])->toBe('42:73')
        ->and(InstanceRemoval::query()->sole()->native_sources)->toBe($captured);
    expect(InstanceRemoval::query()->count())->toBe(1);
    expect($this->instance->refresh()->status)->toBe(InstanceState::Removing);
});

it('admits production release ownership and managed SQL through the same native DAG', function (): void {
    NodeRole::query()->where('node_id', $this->node->id)->delete();
    NodeRole::query()->create(['node_id' => $this->node->id, 'role' => RoleName::AppProd, 'status' => 'active']);
    $user = 'orbit-app-'.$this->instance->project_id;
    $home = '/home/'.$user;
    $this->instance->update(['source_layout' => 'checkout', 'production_user' => $user, 'production_home' => $home,
        'checkout_path' => $home.'/releases/current-release', 'selected_php_version' => '8.5']);
    $server = DatabaseServer::query()->create(['slug' => 'prod-owned', 'node_id' => $this->node->id,
        'driver' => 'pgsql', 'tag' => 'test', 'port' => 5432, 'root_password' => 'private-prod-password', 'status' => 'active']);
    $database = DatabaseConnection::query()->create(['slug' => 'prod-owned', 'driver' => 'pgsql',
        'database_server_id' => $server->id, 'owner_instance_id' => $this->instance->id]);
    $id = $this->deleteJson($this->path, ['force' => true])->assertAccepted()->json('data.id');
    $operation = Operation::query()->findOrFail($id);
    expect($operation->input['environment'])->toBe('production')
        ->and($operation->input['direct_destroy']['members'][0]['source']['user'])->toBe($user)
        ->and($operation->input['direct_destroy']['members'][0]['source']['checkout'])->toBe($home)
        ->and($operation->input['direct_destroy']['teardown'][0]['user'])->toBe($user)
        ->and($operation->input['direct_destroy']['database']['connection_ids'])->toBe([$database->id])
        ->and($operation->input['direct_destroy']['routes'][0]['nodes'][0]['node_id'])->toBe($this->node->id)
        ->and(json_encode($operation->input))->not->toContain('private-prod-password')
        ->and($this->socket->sent)->toHaveCount(1);
    expect(app(InstanceDestroyPhpSecret::class)->resolve($operation, $this->instance->id, '', 'php')->value['production_runtime'])->toBe([]);
    $source = ['instance_id' => $this->instance->id, 'user' => $user, 'group' => $user, 'source_root' => '/home',
        'source_digest' => str_repeat('c', 64), 'source_commit' => str_repeat('a', 40), 'common_repository_path' => $home,
        'source_identity' => '42:73', 'linked_worktree_paths' => [], 'worktree_inventory' => base64_encode('captured-production-worktree'),
        'origin' => $this->instance->project->repository_url];
    $production = ['instance_id' => $this->instance->id, 'project_id' => $this->instance->project_id, 'node_id' => $this->node->id,
        'operation' => $operation->input['removal_id'], 'member' => (string) InstanceRemoval::query()->sole()->members()->sole()->id,
        'user' => $user, 'home' => $home, 'checkout' => $this->instance->checkout_path, 'repository' => $source['origin'], 'force' => true,
        'capture' => ['marker' => true, 'uid' => 1234, 'gid' => 1234, 'group' => $user, 'identity' => '42:73', 'release' => $this->instance->checkout_path,
            'release_identity' => '42:74', 'commit' => str_repeat('a', 40), 'origin' => $source['origin'], 'worktrees' => $source['worktree_inventory'],
            'digest' => str_repeat('c', 64)]];
    app(InstanceDestroyProjection::class)->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'status' => 'running',
        'started_at' => '2026-10-31T11:00:00Z', 'result' => ['source_inspection' => ['members' => [$source], 'production' => $production]]]);
    app(InstanceDestroyProjection::class)->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'status' => 'failed',
        'started_at' => '2026-10-31T11:00:00Z', 'finished_at' => '2026-10-31T11:00:01Z',
        'result' => ['error_code' => 'instance.destroy_failed', 'failed_step' => 'databases'], 'error_code' => 'instance.destroy_failed']);
    $this->withHeader('Idempotency-Key', 'production-retry');
    $next = $this->deleteJson($this->path, ['force' => true])->assertAccepted()->json('data.id');
    $retried = Operation::query()->findOrFail($next);
    $executable = app(InstanceDestroySourceInspection::class)->executable($retried->input);
    expect($next)->not->toBe($id)
        ->and($executable['direct_destroy']['production'])->toBe($production)
        ->and(InstanceRemoval::query()->sole()->members()->sole()->worktree_inventory)->toBe($source['worktree_inventory'])
        ->and(InstanceRemoval::query()->sole()->members()->sole()->origin)->toBe($source['origin']);
});

it('refuses an unowned production account before admission', function (): void {
    NodeRole::query()->where('node_id', $this->node->id)->delete();
    NodeRole::query()->create(['node_id' => $this->node->id, 'role' => RoleName::AppProd, 'status' => 'active']);
    $this->instance->update(['production_user' => 'orbit-app-999', 'production_home' => '/home/orbit-app-999',
        'checkout_path' => '/home/orbit-app-999/releases/initial']);
    $this->deleteJson($this->path, ['force' => true])->assertConflict()->assertJsonPath('error.code', 'instance.removal_conflict');
    expect(Operation::query()->count())->toBe(0)->and($this->instance->refresh()->status)->toBe(InstanceState::Active);
});

it('refuses public cancellation once native removal is admitted', function (): void {
    $id = $this->deleteJson($this->path, ['force' => true])->assertAccepted()->json('data.id');
    $this->call('POST', '/api/v1/operations/'.$id.'/cancel', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertConflict()->assertJsonPath('error.code', 'operation.cancel_refused');
    expect(Operation::query()->findOrFail($id)->cancel_requested_at)->toBeNull();
});

it('admits a failed production clone before its home or account exists with no teardown', function (): void {
    NodeRole::query()->where('node_id', $this->node->id)->delete();
    NodeRole::query()->create(['node_id' => $this->node->id, 'role' => RoleName::AppProd, 'status' => 'active']);
    $user = 'orbit-app-'.$this->instance->project_id;
    $this->instance->update(['status' => 'reserved', 'failed_step' => 'source', 'error_code' => 'instance.clone_failed',
        'starting_commit' => null, 'production_user' => $user, 'production_home' => '/home/'.$user,
        'checkout_path' => '/home/'.$user.'/releases/initial']);
    $id = $this->deleteJson($this->path, ['force' => false])->assertAccepted()->json('data.id');
    $operation = Operation::query()->findOrFail($id);
    expect($operation->input['members'][0]['failed_creation'])->toBeTrue()
        ->and($operation->input['direct_destroy']['teardown'][0]['steps'])->toBe([])
        ->and($this->instance->refresh()->status)->toBe(InstanceState::Removing);
});
