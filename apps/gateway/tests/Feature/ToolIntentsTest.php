<?php

declare(strict_types=1);

use App\Actions\Tools\StartToolOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolIntentProjection;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $gateway = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.1', 'status' => LifecycleStatus::Active]);
    NodeRole::query()->create(['node_id' => $gateway->id, 'role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $this->markAsGateway($gateway);
    $this->node = Node::query()->forceCreate(['id' => 209, 'name' => 'private-dev', 'public_ssh_host' => '192.0.2.9', 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.9', 'status' => LifecycleStatus::Active,
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG', 'ssh_host_fingerprint' => 'SHA256:'.str_repeat('a', 43)]);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    $this->runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    config(['swamp.run_token' => 'fixture-token', 'swamp.read_token' => 'fixture-read', 'swamp.address' => 'swamp.invalid', 'swamp.ca_path' => '/fixture/ca.pem']);
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => $request['payload']['workflowIdOrName'], 'runId' => $this->runId]]];
    app()->instance(WebSocketClient::class, $this->socket);
});

it('starts a full-input write only after admission commits', function (): void {
    DB::beginTransaction();
    $operation = app(StartToolOperationAction::class)->execute('install', $this->node->id, 'composer', 'vendor/package', null);
    expect($this->socket->sent)->toBe([])->and($operation->lock_key)->toBe('node_tools:209:composer');
    DB::commit();
    expect($this->socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-tool-write-209');
    expect($operation->input['setup']['manager'])->toBe('composer')->and($operation->input['ssh']['control_node_id'])->toBe(209);
    $this->node->update(['user' => 'changed', 'wireguard_ip' => '10.44.0.99']);
    $captured = app(ToolIntentProjection::class)->input($operation);
    expect($captured['intent']['user'])->toBe('orbit')->and($captured['ssh']['target']['host'])->toBe('10.44.0.9');
});

it('projects bounded native package data once and rejects another root', function (): void {
    $operation = app(StartToolOperationAction::class)->execute('install', $this->node->id, 'apt', 'jq', null);
    $data = ['run_id' => $this->runId, 'status' => 'succeeded', 'started_at' => '2026-11-01T04:00:00Z', 'finished_at' => '2026-11-01T04:00:01Z', 'result' => ['manager_version' => 'apt 3.0.0', 'installed_version' => '1.7.1', 'password' => 'discard-sentinel']];
    $projection = app(ToolIntentProjection::class);
    expect(fn () => $projection->apply($operation, [...$data, 'run_id' => 'f4c20822-d85f-4212-a60d-a81a52c30baa']))->toThrow(ResourceOperationException::class);
    $projection->apply($operation, $data);
    $projection->apply($operation, $data);
    expect(Tool::query()->sole()->installed_version)->toBe('1.7.1')->and(ToolManagerRecord::query()->sole()->installed_version)->toBe('apt 3.0.0');
    expect($operation->refresh()->result)->not->toHaveKey('password');
    expect($operation->lock_key)->toBeNull()->and($operation->status)->toBe(OperationStatus::Succeeded);
});

it('rejects malformed typed native results without writing Tool state', function (array $result): void {
    $operation = app(StartToolOperationAction::class)->execute('install', $this->node->id, 'apt', 'jq', null);
    expect(fn () => app(ToolIntentProjection::class)->apply($operation, ['run_id' => $this->runId, 'status' => 'succeeded', 'started_at' => '2026-11-01T04:00:00Z', 'finished_at' => '2026-11-01T04:00:01Z', 'result' => $result]))->toThrow(ResourceOperationException::class);
    expect($operation->refresh()->status)->toBe(OperationStatus::Running)
        ->and(Tool::query()->sole()->status)->toBe(ToolStatus::Installing)->and(ToolManagerRecord::query()->sole()->status)->toBe(LifecycleStatus::Provisioning);
})->with([
    [['manager_version' => "3.0.0\nraw"]], [['installed_version' => str_repeat('a', 256)]], [['installed_version' => []]], [['outcome' => 'raw']],
]);
