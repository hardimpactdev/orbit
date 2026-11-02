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

it('renders one database:destroy child per server with the server admin login', function (): void {
    DatabaseConnection::query()->create(['slug' => 'owned', 'driver' => 'mysql', 'database_server_id' => $this->server->id,
        'database' => 'owned_app', 'username' => 'owned_user', 'owner_instance_id' => $this->instance->id]);
    $rows = app(DropOwnedDatabasesAction::class)->snapshot([$this->instance])['servers'];
    [$servers, $copies] = DatabaseOwnedRemoval::servers(41, $rows, true);
    $intent = $servers[0]['intent'];
    expect($intent['login'])->toBe('/run/orbit-database-destroy-owned-41-'.$this->server->id.'.env');
    expect($intent['client'])->toContain('--env-file', $intent['login']);
    expect($intent['statements']['drop'])->toContain('owned_app')->toContain('owned_user');
    expect($intent['ssh_execution']['target']['host'])->toBe($this->dbNode->wireguard_ip);
    expect($copies)->toBe(['server_'.$this->server->id => ['vault' => 'orbit-control-inputs', 'key' => 'database-server-'.$this->server->id.'-client-env']]);
    expect(collect($this->socket->sent)->where('type', 'vault.put')->pluck('payload.value')->all())->toBe(['MYSQL_PWD='.$this->server->root_password."\n"]);
    expect(json_encode($servers))->not->toContain($this->server->root_password);
});

it('names the server login without a vault write once the run has ended', function (): void {
    DatabaseConnection::query()->create(['slug' => 'owned', 'driver' => 'mysql', 'database_server_id' => $this->server->id,
        'database' => 'owned_app', 'username' => 'owned_user', 'owner_instance_id' => $this->instance->id]);
    [, $copies] = DatabaseOwnedRemoval::servers(41, app(DropOwnedDatabasesAction::class)->snapshot([$this->instance])['servers'], false);
    expect($copies['server_'.$this->server->id]['key'])->toBe('database-server-'.$this->server->id.'-client-env');
    expect($this->socket->sent)->toBe([]);
});
