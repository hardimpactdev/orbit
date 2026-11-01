<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeSettingsProjection;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $gateway = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.1', 'status' => LifecycleStatus::Active]);
    NodeRole::query()->create(['node_id' => $gateway->id, 'role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $this->worker = Node::query()->create(['name' => 'worker', 'public_ssh_host' => '192.0.2.17', 'user' => 'orbit', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.17', 'status' => LifecycleStatus::Active]);
    $this->worker->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $this->worker->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->worker->wireguard_ip]);
    $this->gateway = $gateway;
    $this->node = Node::query()->forceCreate(['id' => 209, 'name' => 'canary', 'public_ssh_host' => '192.0.2.9', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.9', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64', 'ssh_host_fingerprint' => 'SHA256:test', 'settings' => ['apps' => ['path' => '/srv/old']]]);
    $this->input = ['agent_pin' => str_repeat('a', 64), 'root' => '/srv/new', 'user' => 'orbit', 'create_missing' => true,
        'protected_roots' => ['/etc'], 'managed_checkouts' => [],
        'settings' => ['apps' => ['path' => '/srv/new']], 'settings_patch' => ['apps' => true, 'task_vms' => false]];
    $this->run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->operation = Operation::query()->create(['kind' => 'node.settings', 'target_type' => 'node', 'target_id' => $this->node->id,
        'node_id' => $this->node->id, 'status' => OperationStatus::Queued, 'sequence' => 1, 'input' => $this->input,
        'state' => ['node_settings_writer' => 'direct', 'swamp_run_id' => $this->run]]);
    $this->body = ['run_id' => $this->run, 'status' => 'succeeded', 'started_at' => '2026-10-30T12:00:00.000Z',
        'finished_at' => '2026-10-30T12:00:01.000Z', 'error_code' => null, 'result' => ['prepared' => true]];
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = fn (array $message): array => [['type' => 'event', 'id' => $message['id'], 'event' => [
        'kind' => 'started', 'workflowName' => 'operation-node-settings', 'runId' => $this->run,
    ]]];
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.run_token' => 'fake.token', 'swamp_workers.nodes' => [$this->node->id]]);

});

it('projects settings idempotently and preserves other members', function (): void {
    $this->node->update(['settings' => ['apps' => ['path' => '/srv/old'], 'foreign' => 'kept']]);
    app(NodeSettingsProjection::class)->apply($this->operation, $this->body);
    app(NodeSettingsProjection::class)->apply($this->operation, $this->body);
    expect($this->node->fresh()->settings)->toMatchArray(['apps' => ['path' => '/srv/new'], 'foreign' => 'kept']);
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Succeeded);
});

it('preserves settings on failed and cancelled results', function (string $status): void {
    app(NodeSettingsProjection::class)->apply($this->operation, [...$this->body, 'status' => $status, 'result' => $status === 'failed' ? ['error_code' => 'node.settings_root_failed'] : [], 'error_code' => $status === 'failed' ? 'node.settings_root_failed' : null]);
    expect($this->node->fresh()->settings)->toBe(['apps' => ['path' => '/srv/old']]);
})->with(['failed', 'cancelled']);

it('preserves a task VM patch made after the apps intent was admitted', function (): void {
    $host = ['cidr' => '10.251.77.0/24', 'max_vms' => 4];
    $this->node->update(['settings' => ['apps' => ['path' => '/srv/old'], 'task_vms' => $host]]);
    app(NodeSettingsProjection::class)->apply($this->operation, $this->body);
    expect($this->node->fresh()->settings['apps'])->toBe(['path' => '/srv/new'])
        ->and($this->node->fresh()->settings['task_vms'])->toMatchArray($host);
});

it('clears the apps override only after preparing the managed default', function (): void {
    $this->operation->update(['input' => [...$this->input, 'root' => null, 'settings' => null]]);
    app(NodeSettingsProjection::class)->apply($this->operation, $this->body);
    expect($this->node->fresh()->settings)->toBeNull();
});
