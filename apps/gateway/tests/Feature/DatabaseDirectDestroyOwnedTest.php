<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\DropOwnedDatabasesAction;
use App\Actions\DatabaseConnections\PrepareOwnedDatabaseDestructionAction;
use App\Domain\DatabaseServers\DatabaseOwnedRemoval;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExecutionIntent;
use App\Models\Operation;
use Tests\Support\FakeRoutePublicationSocket;

require_once __DIR__.'/../Support/ServerDatabaseFixtures.php';
uses(ServerDatabaseFixtures::class);

beforeEach(function (): void {
    $this->prepareServerDatabaseFixtures();
    $this->dbNode->update(['architecture' => 'amd64']);
    config(['swamp.run_token' => 'fake.token']);
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = fn (array $sent): array => [['type' => 'vault.put', 'id' => $sent['id'], 'payload' => ['data' => ['vaultName' => $sent['payload']['vaultName'], 'secretKey' => $sent['payload']['key'], 'vaultType' => 'local_encryption']]]];
    app()->instance(WebSocketClient::class, $this->socket);
});

it('records the owned set without a password and starts no Operation', function (): void {
    $connection = DatabaseConnection::query()->create(['slug' => 'proof', 'driver' => 'mysql', 'database_server_id' => $this->server->id,
        'database' => 'proof', 'username' => 'proof', 'owner_instance_id' => $this->instance->id]);
    $owned = app(PrepareOwnedDatabaseDestructionAction::class)->execute($this->instance);
    expect($owned['connection_ids'])->toBe([$connection->id]);
    expect([$owned['servers'][0]['server_id'], $owned['servers'][0]['node_id']])->toBe([$this->server->id, $this->server->node_id]);
    expect($owned['servers'][0]['intent'])->not->toHaveKey('ssh');
    expect(DatabaseExecutionIntent::recorded($owned['intent_id'])->payload['commands'])->toBe([]);
    expect(json_encode(DatabaseExecutionIntent::recorded($owned['intent_id'])->payload))->not->toContain($this->server->root_password);
    expect(Operation::query()->count())->toBe(0);
});

it('renders one database:destroy child per server with one vault value per batch', function (): void {
    DatabaseConnection::query()->create(['slug' => 'owned', 'driver' => 'mysql', 'database_server_id' => $this->server->id,
        'database' => 'owned_app', 'username' => 'owned_user', 'owner_instance_id' => $this->instance->id]);
    $rows = app(DropOwnedDatabasesAction::class)->snapshot([$this->instance])['servers'];
    [$servers, $copies] = DatabaseOwnedRemoval::servers(41, $rows, true);
    $id = $this->server->id;
    expect(array_slice($servers[0]['intent']['admin_client'], 0, 4))->toBe(['docker', 'exec', '-i', 'orbit-process-'.$this->server->process_id.'-beast-mysql']);
    expect($servers[0]['intent']['ssh_execution']['target']['host'])->toBe($this->dbNode->wireguard_ip);
    expect(array_keys($copies))->toBe(["server_{$id}_fence", "server_{$id}_drop", "server_{$id}_gone", "server_{$id}_restore"]);
    expect($copies["server_{$id}_drop"])->toBe(['vault' => 'orbit-control-inputs', 'key' => "database-destroy-owned-41-{$id}-drop"]);
    $puts = collect($this->socket->sent)->where('type', 'vault.put')->pluck('payload.value', 'payload.key');
    expect($puts["database-destroy-owned-41-{$id}-drop"])->toStartWith($this->server->root_password."\n")->toContain('`owned_app`')->toContain("'owned_user'");
    expect(json_encode($servers))->not->toContain($this->server->root_password);
});

it('names the batch keys without a vault write once the run has ended', function (): void {
    DatabaseConnection::query()->create(['slug' => 'owned', 'driver' => 'mysql', 'database_server_id' => $this->server->id,
        'database' => 'owned_app', 'username' => 'owned_user', 'owner_instance_id' => $this->instance->id]);
    [, $copies] = DatabaseOwnedRemoval::servers(41, app(DropOwnedDatabasesAction::class)->snapshot([$this->instance])['servers'], false);
    expect($copies['server_'.$this->server->id.'_gone']['key'])->toBe('database-destroy-owned-41-'.$this->server->id.'-gone');
    expect($this->socket->sent)->toBe([]);
});
