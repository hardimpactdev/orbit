<?php

declare(strict_types=1);

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Models\DatabaseExecutionIntent;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    config(['swamp.direct_writers' => ['database.sqlite_cleanup' => 'direct']]);
    $gateway = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.1', 'status' => LifecycleStatus::Active]);
    NodeRole::query()->create(['node_id' => $gateway->id, 'role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $this->worker = Node::query()->create(['name' => 'services', 'public_ssh_host' => '192.0.2.17', 'user' => 'orbit', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.17', 'status' => LifecycleStatus::Active]);
    $this->worker->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $this->worker->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->worker->wireguard_ip]);
    $this->node = Node::query()->create(['public_ssh_host' => '192.0.2.9', 'user' => 'orbit', 'platform' => 'linux', 'name' => 'private-dev', 'architecture' => 'amd64', 'wireguard_ip' => '10.44.0.9', 'status' => LifecycleStatus::Active]);
    $this->intent = DatabaseExecutionIntent::query()->create(['target_type' => 'instance', 'target_id' => $this->node->id, 'node_id' => $this->node->id, 'payload' => ['commands' => ['database-apply' => ['name' => 'instance.copy-sqlite', 'input' => ['operation' => 'cleanup', 'directory' => '/tmp/orbit-sqlite-clone-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']]], 'projection' => []]]);
    $this->operation = Operation::query()->create(['kind' => 'database.sqlite_cleanup', 'target_type' => 'instance',
        'target_id' => $this->node->id, 'node_id' => $this->node->id, 'status' => OperationStatus::Queued,
        'sequence' => 1, 'state' => ['database_cleanup_writer' => 'direct', 'swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676'], 'input' => ['intent_id' => $this->intent->id]]);
    $this->runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->path = '/api/v1/direct-operations/'.$this->operation->id;
    $this->body = ['run_id' => $this->runId];
    $this->agents = new FakeAgentCommandRunner()->answer('instance.copy-sqlite', ['state' => 'cleaned']);
    app()->instance(AgentCommandRunner::class, $this->agents);
});

it('declares no Gateway cleanup relay', function (): void {
    $this->postJson($this->path.'/primitive', $this->body)->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
    expect($this->agents->calls)->toBe([]);
});

it('excludes direct publications from discovery and refuses the replaced executor paths', function (): void {
    $response = $this->getJson('/api/v1/operation-executor/claimable')->assertOk();
    expect(collect($response->json('data.kinds'))->firstWhere('kind', 'database.sqlite_cleanup')['claimable'])->toBe(0);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'database.sqlite_cleanup', 'worker' => 'old', 'operation_id' => $this->operation->id])->assertOk()->assertJsonPath('data', null);
    $this->postJson('/api/v1/operation-executor/'.$this->operation->id.'/heartbeat', ['worker' => 'old'])->assertUnprocessable();
    expect($this->agents->calls)->toBe([]);
});

it('requires the existing executor identity and rejects caller Node command and input overrides', function (): void {
    $this->postJson($this->path.'/primitive', [...$this->body, 'node_id' => $this->worker->id])->assertUnprocessable();
    $this->postJson($this->path.'/primitive', [...$this->body, 'input' => ['content' => 'foreign.test {}']])->assertUnprocessable();
    $this->postJson($this->path.'/primitive', [...$this->body, 'command' => 'arbitrary'])->assertUnprocessable();
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->postJson($this->path.'/primitive', $this->body)->assertForbidden();
    expect($this->agents->calls)->toBe([]);
});

it('projects only typed safe Swamp results and associates the native run without a lease', function (): void {
    $started = ['run_id' => $this->runId, 'status' => 'running', 'started_at' => '2026-10-30T12:00:00.000Z'];
    $this->postJson($this->path.'/result', $started)->assertOk();
    $finished = [...$started, 'status' => 'succeeded', 'finished_at' => '2026-10-30T12:00:01.000Z',
        'result' => ['backend' => 'absent', 'password' => 'private-test-password']];
    $this->postJson($this->path.'/result', $finished)->assertOk()->assertJsonPath('data.result', ['backend' => 'absent']);
    $this->postJson($this->path.'/result', $finished)->assertOk();
    $operation = $this->operation->fresh();
    expect($operation->state)->toHaveKeys(['swamp_run_id', 'steps'])->not->toHaveKeys(['executor_runs', 'executor_effect_identity', 'executor_lease_node_id']);
    expect($operation->attempts)->toBe(0);
    expect($operation->worker)->toBeNull();
    expect($operation->steps()->first()->status->value)->toBe('succeeded');
    expect(json_encode($operation->toArray()))->not->toContain('private-test-password');
});

it('refuses an invalid success and another run before projection', function (): void {
    $body = [...$this->body, 'status' => 'succeeded', 'started_at' => '2026-10-30T12:00:00.000Z', 'finished_at' => '2026-10-30T12:00:01.000Z'];
    $this->postJson($this->path.'/result', [...$body, 'result' => []])->assertUnprocessable();
    $this->operation->update(['state' => ['database_cleanup_writer' => 'direct', 'swamp_run_id' => $this->runId]]);
    $this->postJson($this->path.'/primitive', ['run_id' => 'fabd4059-fb6c-49d8-940e-01ae58aa3f38'])->assertConflict();
    expect($this->agents->calls)->toBe([]);
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Queued);
});

it('submits once after commit and records a native run without acquiring a target hold', function (): void {
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $sent): array => [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-database-cleanup', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'fake.token']);
    DB::beginTransaction();
    $operation = app(StartOperationAction::class)->execute('database.sqlite_cleanup', 'instance', $this->node->id, $this->node->id, ['intent_id' => $this->intent->id], 'test', null);
    expect($socket->sent)->toBe([]);
    DB::commit();
    expect($socket->sent)->toHaveCount(1);
    expect($socket->sent[0])->toMatchArray(['type' => 'workflow.run', 'payload' => [
        'workflowIdOrName' => 'operation-database-cleanup', 'inputs' => ['operation_id' => $operation->id, 'kind' => 'database.sqlite_cleanup', 'node_id' => $this->node->id],
        'runtimeTags' => ['orbit_start_key' => 'operation:'.$operation->id],
    ]]);
    expect($operation->fresh()->lock_key)->toBeNull();
    expect($operation->fresh()->state['swamp_run_id'])->toBe('a075eb23-7ac4-4487-80d1-57a038a92676');
    expect($socket->closed)->toBeTrue();
});

it('keeps an unreadable native history uncertain without resubmitting or reporting a false failure', function (): void {
    $this->operation->update(['state' => array_diff_key($this->operation->state, ['swamp_run_id' => true])]);
    $socket = new FakeRoutePublicationSocket(false);
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'fake.token']);
    expect(fn () => app(StartSwampRun::class)->start($this->operation))->toThrow(ResourceOperationException::class);
    expect(array_column($socket->sent, 'type'))->toBe(['workflow.run', 'workflow.run.search']);
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Queued);
    expect($this->operation->fresh()->attempts)->toBe(0);
    expect($socket->closed)->toBeTrue();
});

it('keeps the previous Swamp writer by default and binds Operations through switch and rollback', function (): void {
    config(['swamp.direct_writers' => []]);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $sent): array => [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-database-cleanup', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'fake.token']);
    $start = app(StartOperationAction::class);
    $polled = $start->execute('database.sqlite_cleanup', 'instance', $this->node->id, $this->node->id, ['intent_id' => $this->intent->id], 'test', null);
    expect($polled->state['database_cleanup_writer'])->toBe('polled');
    expect($socket->sent)->toBe([]);
    $this->postJson('/api/v1/direct-operations/'.$polled->id.'/primitive', $this->body)->assertUnprocessable();

    config(['swamp.direct_writers' => ['database.sqlite_cleanup' => 'direct']]);
    $direct = $start->execute('database.sqlite_cleanup', 'instance', $this->node->id, $this->node->id, ['intent_id' => $this->intent->id], 'test', null);
    expect($direct->fresh()->state['database_cleanup_writer'])->toBe('direct');
    expect($socket->sent)->toHaveCount(1);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $polled->kind, 'worker' => 'previous', 'operation_id' => $polled->id])->assertOk()->assertJsonPath('data.id', $polled->id);

    config(['swamp.direct_writers' => ['database.sqlite_cleanup' => 'polled']]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $direct->kind, 'worker' => 'previous', 'operation_id' => $direct->id])->assertOk()->assertJsonPath('data', null);
    $this->getJson('/api/v1/direct-operations/'.$direct->id.'?run_id='.$direct->fresh()->state['swamp_run_id'])->assertOk()->assertJsonPath('data.kind', 'database.sqlite_cleanup');
    expect($this->agents->calls)->toBe([]);
});

it('keeps pre-rollout Operations with no writer stamp on their original Swamp path', function (): void {
    $this->operation->update(['state' => null]);
    $this->postJson($this->path.'/primitive', $this->body)->assertUnprocessable();
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $this->operation->kind, 'worker' => 'previous', 'operation_id' => $this->operation->id])->assertOk()->assertJsonPath('data.id', $this->operation->id);
    expect($this->agents->calls)->toBe([]);
});

it('exposes no raw admitted input through the shared read boundary', function (): void {
    $this->operation->update(['input' => [...$this->operation->input, 'private' => 'never-return-this']]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.input', ['variant' => 'clone', 'agent_sha256' => app(AgentDelivery::class)->pin($this->node), 'directory' => '/tmp/orbit-sqlite-clone-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'])->assertDontSee('never-return-this');
});

it('rejects contradictory success and filters cleanup results to their public fields', function (): void {
    $body = [...$this->body, 'status' => 'succeeded', 'started_at' => '2026-10-30T12:00:00.000Z', 'finished_at' => '2026-10-30T12:00:01.000Z'];
    $this->postJson($this->path.'/result', [...$body, 'result' => ['backend' => 'absent', 'error_code' => 'database.failed'], 'error_code' => 'database.failed'])->assertUnprocessable();
    $this->postJson($this->path.'/result', [...$body, 'result' => ['backend' => 'absent', 'state' => 'private-detail', 'bytes' => 999]])->assertOk()->assertJsonPath('data.result', ['backend' => 'absent']);
});

it('projects only the admitted cleanup variant and never command or private intent fields', function (): void {
    $payload = $this->intent->payload;
    $payload['commands']['database-apply']['input']['password'] = 'not-exported';
    $payload['projection']['private'] = 'not-exported';
    $this->intent->update(['payload' => $payload]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()
        ->assertJsonPath('data.input.variant', 'clone')->assertJsonMissingPath('data.input.command')
        ->assertJsonMissingPath('data.input.password')->assertDontSee('not-exported');
});

it('refuses a foreign intent and a path outside the admitted cleanup form', function (): void {
    $this->intent->update(['target_id' => $this->node->id + 100]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertUnprocessable();
    $payload = $this->intent->payload;
    $payload['commands']['database-apply']['input']['directory'] = '/tmp/orbit-sqlite-clone-../foreign';
    $this->intent->update(['target_id' => $this->node->id, 'payload' => $payload]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertUnprocessable();
    expect($this->agents->calls)->toBe([]);
});

it('projects bound seed cleanup fields and rejects another target or snapshot identity', function (): void {
    $identity = str_repeat('b', 64);
    $input = ['mode' => 'cleanup', 'base' => '/home/orbit/apps/proof/default', 'source_path' => '/home/orbit/apps/proof/default/database.sqlite',
        'source_user' => 'orbit', 'transport_user' => 'orbit', 'operation' => $identity,
        'state_dir' => '/var/lib/orbit/app-instance-sqlite-seeds/source-21-target-'.$this->node->id,
        'snapshot_path' => '/tmp/orbit-sqlite-'.$identity.'.sqlite'];
    $payload = ['commands' => ['database-apply' => ['name' => 'instance.prepare-sqlite-seed', 'input' => $input]], 'projection' => []];
    $this->intent->update(['payload' => $payload]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.input.variant', 'seed')
        ->assertJsonPath('data.input.operation', $identity)->assertJsonMissingPath('data.input.mode');
    $payload['commands']['database-apply']['input']['snapshot_path'] = '/tmp/foreign.sqlite';
    $this->intent->update(['payload' => $payload]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertUnprocessable();
});

it('refuses first read and projection until the Gateway start response binds the run', function (): void {
    $this->operation->update(['state' => ['database_cleanup_writer' => 'direct']]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertConflict()->assertJsonPath('error.code', 'operation.run_unbound');
    $this->postJson($this->path.'/result', [...$this->body, 'status' => 'running', 'started_at' => '2026-10-30T12:00:00.000Z'])
        ->assertConflict()->assertJsonPath('error.code', 'operation.run_unbound');
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $sent): array => [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-database-cleanup', 'runId' => $this->runId]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'fake.token']);
    app(StartSwampRun::class)->start($this->operation);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.input.variant', 'clone');
    expect($this->agents->calls)->toBe([]);
});

it('lets a configured Node worker read only its own bound cleanup', function (): void {
    $this->node->accessibleNodes()->attach($this->worker->accessibleNodes()->first());
    config(['operation_executor.worker_node_ids' => [$this->node->id, $this->worker->id], 'dependency_detection.services_node_id' => 999999]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => $this->worker->wireguard_ip]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertForbidden()->assertJsonPath('error.code', 'operation.executor_forbidden');
});
