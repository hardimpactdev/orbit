<?php

declare(strict_types=1);

use App\Actions\DatabaseServers\CreateDatabaseServerAction;
use App\Data\DatabaseServers\CreateDatabaseServerData;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\DatabaseServer;
use App\Models\Node;
use Tests\Support\FakeRoutePublicationSocket;

it('publishes an empty open Valkey ACL payload through the shared vault execution copy', function (): void {
    $gateway = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'platform' => 'linux', 'status' => 'active', 'wireguard_ip' => '10.44.0.1', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.1']));
    $node = Node::query()->create(['name' => 'valkey-dev', 'platform' => 'linux', 'architecture' => 'amd64', 'status' => 'active', 'wireguard_ip' => '10.44.0.9', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9']);
    $node->accessibleNodes()->attach($gateway);
    config(['swamp.run_token' => 'fake.token']);
    $runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $sent): array => ($sent['type'] === 'vault.put' ? [['type' => 'vault.put', 'id' => $sent['id'], 'payload' => ['data' => ['vaultName' => $sent['payload']['vaultName'], 'secretKey' => $sent['payload']['key'], 'vaultType' => 'local_encryption']]]] : [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-database-server-create', 'runId' => $runId]]]);
    app()->instance(WebSocketClient::class, $socket);
    DatabaseServer::query()->create(['slug' => 'proof', 'driver' => 'redis', 'node_id' => $node->id, 'tag' => '8.0', 'port' => 6379,
        'root_password' => 'private-valkey-root', 'default_open' => true, 'status' => 'provisioning']);
    $operation = app(CreateDatabaseServerAction::class)->execute(new CreateDatabaseServerData('proof', $node->id, DatabaseDriver::Redis, '8.0', 6379));
    $material = json_decode($socket->sent[0]['payload']['value'], true, flags: JSON_THROW_ON_ERROR);
    expect($socket->sent[0]['type'])->toBe('vault.put');
    expect($socket->sent[0]['payload']['key'])->toBe('database-server-create-intent-'.$operation->input['intent_id']);
    expect($socket->sent[1]['payload']['inputs']['execution_secrets']['server_create'])->toBe(['vault' => 'orbit-control-inputs', 'key' => 'database-server-create-intent-'.$operation->input['intent_id']]);
    expect(json_encode($socket->sent[1]['payload']['inputs']))->not->toContain($material['password']);
    expect($material['payload'])->toBe(['users' => [], 'default_open' => true]);
    expect($material['password'])->toBe(DatabaseServer::query()->sole()->root_password);
    expect(json_encode($operation->fresh()->toArray()))->not->toContain($material['password']);
});
