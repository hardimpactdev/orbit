<?php

declare(strict_types=1);

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeSettingsProjection;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $gateway = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.1', 'status' => LifecycleStatus::Active]);
    NodeRole::query()->create(['node_id' => $gateway->id, 'role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $this->worker = Node::query()->create(['name' => 'worker', 'public_ssh_host' => '192.0.2.17', 'user' => 'orbit', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.17', 'status' => LifecycleStatus::Active]);
    $this->worker->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $this->worker->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->worker->wireguard_ip]);
    $this->gateway = $gateway;
    $this->node = Node::query()->create(['name' => 'canary', 'public_ssh_host' => '192.0.2.9', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.9', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64', 'settings' => ['apps' => ['path' => '/srv/old']]]);
    $this->input = ['agent_pin' => str_repeat('a', 64), 'root' => '/srv/new', 'user' => 'orbit', 'group' => 'orbit', 'create_missing' => true,
        'settings' => ['apps' => ['path' => '/srv/new']], 'settings_patch' => ['apps' => true, 'task_vms' => false]];
    $this->run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->operation = Operation::query()->create(['kind' => 'node.settings', 'target_type' => 'node', 'target_id' => $this->node->id,
        'node_id' => $this->node->id, 'status' => OperationStatus::Queued, 'sequence' => 1, 'input' => $this->input,
        'state' => ['node_settings_writer' => 'direct', 'swamp_run_id' => $this->run]]);
    $this->path = '/api/v1/direct-operations/'.$this->operation->id.'/result';
    $this->body = ['run_id' => $this->run, 'status' => 'succeeded', 'started_at' => '2026-10-30T12:00:00.000Z',
        'finished_at' => '2026-10-30T12:00:01.000Z', 'result' => ['prepared' => true]];
});

it('keeps the old writer by default', function (): void {
    $op = app(StartOperationAction::class)->execute('node.settings', 'node', $this->node->id, $this->node->id, $this->input, 'test', null);
    expect($op->state['node_settings_writer'])->toBe('polled');
    expect($op->lock_key)->toBe('node:'.$this->node->id);
});

it('binds only the Gateway start response and refuses first reads until bound', function (): void {
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $message): array => [['type' => 'event', 'id' => $message['id'], 'event' => [
        'kind' => 'started', 'workflowName' => 'operation-node-settings', 'runId' => $this->run,
    ]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.direct_writers' => ['node.settings' => 'direct'], 'swamp.run_token' => 'fake.token']);
    DB::beginTransaction();
    $op = app(StartOperationAction::class)->execute('node.settings', 'node', $this->node->id, $this->node->id, $this->input, 'test', null);
    $path = '/api/v1/direct-operations/'.$op->id.'?run_id='.$this->run;
    expect($socket->sent)->toBe([]);
    $this->getJson($path)->assertConflict();
    expect($op->fresh()->state)->not->toHaveKey('swamp_run_id');
    DB::commit();
    expect($socket->sent)->toHaveCount(1);
    expect($socket->sent[0]['payload'])->toBe(['workflowIdOrName' => 'operation-node-settings',
        'inputs' => ['operation_id' => $op->id, 'kind' => 'node.settings', 'node_id' => $this->node->id]]);
    $this->getJson($path)->assertOk()->assertJsonPath('data.input.storage.root', '/srv/new');
    expect($op->fresh()->lock_key)->toBeNull();
    expect($op->fresh()->state['swamp_run_id'])->toBe($this->run);
    expect($op->fresh()->worker)->toBeNull();
    expect($op->fresh()->attempts)->toBe(0);
});

it('projects settings idempotently and preserves other members', function (): void {
    $this->node->update(['settings' => ['apps' => ['path' => '/srv/old'], 'foreign' => 'kept']]);
    $this->postJson($this->path, $this->body)->assertOk();
    $this->postJson($this->path, $this->body)->assertOk();
    expect($this->node->fresh()->settings)->toMatchArray(['apps' => ['path' => '/srv/new'], 'foreign' => 'kept']);
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Succeeded);
});

it('preserves settings on failed and cancelled results', function (string $status): void {
    $this->postJson($this->path, [...$this->body, 'status' => $status, 'result' => $status === 'failed' ? ['error_code' => 'node.settings_root_failed'] : [], 'error_code' => $status === 'failed' ? 'node.settings_root_failed' : null])->assertOk();
    expect($this->node->fresh()->settings)->toBe(['apps' => ['path' => '/srv/old']]);
})->with(['failed', 'cancelled']);

it('rejects wrong runs unproven success input overrides and family relays', function (): void {
    $this->postJson($this->path, [...$this->body, 'run_id' => 'fabd4059-fb6c-49d8-940e-01ae58aa3f38'])->assertConflict();
    $this->postJson($this->path, [...$this->body, 'result' => []])->assertUnprocessable();
    $this->postJson($this->path, [...$this->body, 'command' => 'foreign'])->assertUnprocessable();
    $this->postJson('/api/v1/direct-operations/'.$this->operation->id.'/primitive', ['run_id' => $this->run])->assertUnprocessable();
});

it('keeps direct settings out of claims and old intents claimable after a switch', function (): void {
    config(['swamp.direct_writers' => ['node.settings' => 'direct']]);
    $claim = ['kind' => 'node.settings', 'worker' => 'old', 'operation_id' => $this->operation->id];
    $this->postJson('/api/v1/operation-executor/claim', $claim)->assertOk()->assertJsonPath('data', null);
    $this->operation->update(['state' => ['node_settings_writer' => 'polled']]);
    $this->postJson('/api/v1/operation-executor/claim', $claim)->assertOk()->assertJsonPath('data.id', $this->operation->id);
});

it('allows an approved worker only for its admitted Node', function (): void {
    $this->node->accessibleNodes()->attach($this->gateway);
    config(['operation_executor.worker_node_ids' => [$this->node->id]]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->getJson('/api/v1/direct-operations/'.$this->operation->id.'?run_id='.$this->run)->assertOk();
    $this->operation->update(['node_id' => $this->gateway->id]);
    $this->getJson('/api/v1/direct-operations/'.$this->operation->id.'?run_id='.$this->run)->assertForbidden();
});

it('captures the agent pin with direct owning admission and exposes only safe input', function (): void {
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $this->worker->accessibleNodes()->attach($this->node);
    $resolver = Mockery::mock(ManagedUserAccountResolver::class);
    $resolver->shouldReceive('resolve')->andReturn(new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));
    app()->instance(ManagedUserAccountResolver::class, $resolver);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $message): array => [['type' => 'event', 'id' => $message['id'], 'event' => [
        'kind' => 'started', 'workflowName' => 'operation-node-settings', 'runId' => $this->run,
    ]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.direct_writers' => ['node.settings' => 'direct'], 'swamp.run_token' => 'fake.token']);
    $response = $this->patchJson('/api/v1/nodes/'.$this->node->id.'/settings', ['apps' => ['path' => '/srv/new']])->assertAccepted();
    $operation = Operation::findOrFail($response->json('data.id'));
    expect($operation->input['agent_pin'])->toBe(app(AgentDelivery::class)->pin($this->node));
    expect(array_keys(app(NodeSettingsProjection::class)->input($operation)))->toBe(['agent_pin', 'storage']);
});
