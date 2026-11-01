<?php

declare(strict_types=1);

use App\Domain\Instances\DevelopmentInstanceDestroyProjection;
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
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9']);
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
    app(DevelopmentInstanceDestroyProjection::class)->apply($operation, [
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
        ->and($operation->input['direct_destroy']['database']['sqlite'][0]['connection_id'])->toBe($database->id)
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

it('refuses an unported managed removal before source reads or intent writes', function (): void {
    $server = DatabaseServer::query()->create(['slug' => 'dispatch-managed', 'node_id' => $this->node->id,
        'driver' => 'mysql', 'tag' => 'test', 'port' => 3306, 'root_password' => 'fixture-private-password', 'status' => 'active']);
    DatabaseConnection::query()->create(['slug' => 'dispatch-managed', 'driver' => 'mysql',
        'database_server_id' => $server->id, 'owner_instance_id' => $this->instance->id]);
    $this->source->shouldNotReceive('inspect');
    $this->deleteJson($this->path, ['force' => true])->assertStatus(503)->assertJsonPath('error.code', 'operation.kind_unavailable');
    expect(Operation::query()->count())->toBe(0);
    expect($this->instance->refresh()->status)->toBe(InstanceState::Active);
    expect(InstanceRemoval::query()->count())->toBe(0);
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
    app(DevelopmentInstanceDestroyProjection::class)->apply($first, [
        'run_id' => $first->state['swamp_run_id'], 'status' => 'running', 'started_at' => '2026-10-31T04:00:00Z',
        'result' => ['source_inspection' => $captured], 'error_code' => null]);
    app(DevelopmentInstanceDestroyProjection::class)->apply($first, [
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
        ->and($retried->input['direct_destroy']['database']['sqlite'][0]['connection_id'])->toBe($database->id)
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
