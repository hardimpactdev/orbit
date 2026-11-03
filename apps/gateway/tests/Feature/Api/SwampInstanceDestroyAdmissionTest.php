<?php

declare(strict_types=1);

use App\Domain\Instances\DevelopmentInstanceDestroyProjection;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationRunBudgets;
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
use App\Models\ProjectLifecycleStep;
use App\Models\Route;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    config(['swamp.run_token' => 'fixture.shared.token']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => $request['type'] === 'vault.put'
        ? [['type' => 'vault.put', 'id' => $request['id'], 'payload' => ['data' => ['vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['key'], 'vaultType' => 'local_encryption']]]]
        : [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-instance-destroy', 'runId' => (string) Str::uuid()]]];
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
        // A normal removal mints the read token for its pushed check, as a credential line; a forced destroy fetches nothing.
        ->and(array_column($this->socket->sent, 'type'))->toBe($force ? ['workflow.run'] : ['vault.put', 'vault.put', 'workflow.run'])
        ->and(collect($this->socket->sent)->last()['payload']['workflowIdOrName'])->toBe('operation-instance-destroy')
        ->and(data_get(collect($this->socket->sent)->last(), 'payload.inputs.execution_secrets.git_credential.key'))->toBe($force ? null : 'instance-git-credential-'.$id)
        ->and($this->instance->refresh()->status)->toBe(InstanceState::Removing)
        ->and(InstanceRemoval::query()->count())->toBe(1);
    Queue::assertNothingPushed();
})->with([false, true]);

it('budgets the run for its teardown steps and each Route it withdraws', function (bool $routed): void {
    ProjectLifecycleStep::query()->create(['project_id' => $this->instance->project_id, 'phase' => 'teardown', 'position' => 0,
        'name' => 'stop', 'command' => 'true', 'timeout_seconds' => 30]);
    if ($routed) {
        $route = Route::query()->create(['project_id' => $this->instance->project_id, 'node_id' => $this->node->id,
            'domain' => 'isolated.removal-proof.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
        $route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
        $route->update(['status' => 'active', 'sites_published' => true]);
    }
    $this->withHeader('Idempotency-Key', 'destroy-budget-'.(int) $routed);
    $id = $this->deleteJson($this->path, ['force' => true])->assertAccepted()->json('data.id');
    $operation = Operation::query()->findOrFail($id);
    expect($operation->input['run_budget_seconds'])->toBe(60 + 30 + ($routed ? 180 : 0))
        ->and(OperationRunBudgets::seconds($operation))->toBe(60 + 30 + ($routed ? 180 : 0));
})->with(['without a Route' => false, 'with a Route' => true]);

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
        'result' => ['steps' => ['Check work is pushed' => ['status' => 'failed']], 'summary' => ['failed_steps' => ['Check work is pushed']]],
        'error_code' => 'operation.native_failed']);

    expect($operation->refresh()->status->value)->toBe('failed')
        ->and($operation->error_code)->toBe('instance.remove_refused')
        ->and($this->instance->refresh()->status)->toBe(InstanceState::Removing)
        ->and(InstanceRemoval::query()->sole()->native_sources)->toBeNull();
    Queue::assertNothingPushed();
})->with([false, true]);

it('validates strict force input before inspecting source', function (): void {
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

it('drops an owned server database through a database:destroy child with one vault value per batch', function (): void {
    $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $this->node->id, 'name' => 'dispatch-managed', 'runtime' => 'docker',
        'runtime_config' => ['image' => 'mysql:8.4', 'command' => ['mysqld'], 'environment' => [], 'ports' => [], 'volumes' => []],
        'restart_policy' => 'unless-stopped', 'desired_state' => 'running', 'status' => 'active']);
    $server = DatabaseServer::query()->create(['slug' => 'dispatch-managed', 'node_id' => $this->node->id, 'process_id' => $process->id,
        'driver' => 'mysql', 'tag' => 'test', 'port' => 3306, 'root_password' => 'fixture-private-password', 'status' => 'active']);
    DatabaseConnection::query()->create(['slug' => 'dispatch-managed', 'driver' => 'mysql', 'host' => '10.44.0.9', 'port' => 3306,
        'database' => 'dispatch_managed', 'username' => 'dispatch_managed', 'password' => 'fixture-connection-password',
        'database_server_id' => $server->id, 'owner_instance_id' => $this->instance->id]);
    $this->withHeader('Idempotency-Key', 'destroy-managed')->deleteJson($this->path, ['force' => true])->assertAccepted();
    $run = collect($this->socket->sent)->firstWhere('type', 'workflow.run')['payload']['inputs'];
    $key = 'database-destroy-owned-'.$run['intent']['direct_destroy']['database']['intent_id'].'-'.$server->id.'-drop';
    $child = $run['intent']['direct_destroy']['database']['servers'][0];

    expect(collect($this->socket->sent)->where('type', 'vault.put')->pluck('payload.key')->all())->toContain($key)
        ->and($run['execution_secrets']['server_'.$server->id.'_drop'])->toBe(['vault' => 'orbit-control-inputs', 'key' => $key])
        ->and($child['server_id'])->toBe($server->id)
        ->and($child['intent']['ssh_execution']['target']['host'])->toBe('10.44.0.9')
        ->and(json_encode($run))->not->toContain('fixture-private-password')->not->toContain('fixture-connection-password')
        ->and($this->instance->refresh()->status)->toBe(InstanceState::Removing);
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
        // A forced removal pushes no read token; each run starts once.
        ->and(array_column($this->socket->sent, 'type'))->toBe(['workflow.run', 'workflow.run']);
    expect(Operation::query()->count())->toBe(2);
    expect(InstanceRemoval::query()->count())->toBe(1);
    expect($this->instance->refresh()->status)->toBe(InstanceState::Removing);
});
