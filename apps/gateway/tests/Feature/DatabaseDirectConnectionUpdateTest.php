<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\DatabaseConnectionUpdateProjection;
use App\Domain\Extensions\ExtensionStore;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->gateway = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']));
    $this->node = Node::query()->create(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAINqVuK+N4gL/sDvGNKnGrYhEtEFnZx9hvH+134PsQgND', 'name' => 'database', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9']);
    $this->node->accessibleNodes()->attach($this->gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    config(['swamp.run_token' => 'fake.token']);
    $this->runId = 'e1589ed9-996b-4c9c-aa75-7749ef519210';
    $socket = $this->socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $sent): array => $sent['type'] === 'vault.put' ? [['type' => 'vault.put', 'id' => $sent['id'], 'payload' => ['data' => ['vaultName' => $sent['payload']['vaultName'], 'secretKey' => $sent['payload']['key'], 'vaultType' => 'local_encryption']]]] : [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-database-connection-update', 'runId' => $this->runId]]];
    app()->instance(WebSocketClient::class, $socket);
    $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $this->node->id, 'name' => 'cache', 'runtime' => 'docker', 'runtime_config' => [], 'restart_policy' => 'unless-stopped', 'desired_state' => 'running', 'status' => 'active']);
    $this->server = DatabaseServer::query()->create(['slug' => 'cache', 'driver' => 'redis', 'node_id' => $this->node->id, 'process_id' => $process->id, 'port' => 6379, 'tag' => '8.1', 'root_password' => 'private-admin-sentinel', 'status' => 'active']);
    $this->connection = DatabaseConnection::query()->create(['slug' => 'cache-client', 'driver' => 'redis', 'database_server_id' => $this->server->id, 'node_id' => $this->node->id, 'host' => $this->node->wireguard_ip, 'port' => 6379, 'database' => '0', 'username' => 'cache', 'password' => 'private-client-sentinel']);
});

function update_callback(string $runId, string $status, array $result = [], ?string $code = null): array
{
    return ['run_id' => $runId, 'status' => $status, 'started_at' => '2026-10-31T09:00:00.000Z', 'finished_at' => '2026-10-31T09:00:01.000Z', 'result' => $result, 'error_code' => $code];
}

/** The native result of one `Check the index fits` run, as Swamp's run data gives it. */
function update_check(string $stdout): array
{
    return ['steps' => ['Check the index fits' => ['status' => 'succeeded', 'stdout' => $stdout, 'exit_code' => 0]]];
}

it('checks a new Valkey index with AUTH from the vault and applies it below the live count', function (): void {
    $response = $this->patchJson('/api/v1/database-connections/cache-client', ['database' => '3'])->assertAccepted()->assertJsonPath('data.kind', 'database.update');
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    expect($operation->node_id)->toBe($this->node->id)->and($this->connection->fresh()->database)->toBe('0');
    $copy = collect($this->socket->sent)->firstWhere('type', 'vault.put');
    expect($copy['payload']['key'])->toBe('database-update-intent-'.$operation->input['intent_id']);
    expect($copy['payload']['value'])->toBe("AUTH default private-admin-sentinel\nCONFIG GET databases\n");
    $start = collect($this->socket->sent)->firstWhere('type', 'workflow.run');
    expect($start['payload']['inputs']['intent'])->toMatchArray(['container' => 'orbit-process-'.$this->server->process_id.'-cache', 'index' => 3]);
    expect(json_encode($start))->not->toContain('private-admin-sentinel', 'private-client-sentinel');
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->postJson('/api/v1/direct-operations/'.$operation->id.'/result', update_callback($this->runId, 'succeeded', update_check("OK\ndatabases\n16\n")))->assertOk();
    expect($this->connection->fresh()->database)->toBe('3')->and($operation->fresh()->result)->toBe(['backend' => 'active']);
});

it('fails an index at or above the live count, or an unreadable count, and keeps the connection', function (string $stdout, string $code): void {
    $id = $this->patchJson('/api/v1/database-connections/cache-client', ['database' => '16'])->assertAccepted()->json('data.id');
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->postJson('/api/v1/direct-operations/'.$id.'/result', update_callback($this->runId, 'succeeded', update_check($stdout)))->assertOk();
    $operation = Operation::query()->findOrFail($id);
    expect($this->connection->fresh()->database)->toBe('0')->and($operation->status->value)->toBe('failed')
        ->and($operation->error_code)->toBe($code)->and($operation->failed_step)->toBe('Check the index fits');
})->with([["OK\ndatabases\n16\n", 'database.index_invalid'], ["WRONGPASS invalid username-password pair\n(error) NOAUTH Authentication required.\n", 'database.server_command_failed']]);

it('writes a record-only change at once with no run, keeping the password encrypted', function (): void {
    $connection = DatabaseConnection::query()->create(['slug' => 'external', 'driver' => 'mysql', 'host' => '192.0.2.80', 'database' => 'app', 'username' => 'app', 'password' => 'old-private']);
    $response = $this->patchJson('/api/v1/database-connections/external', ['password' => 'proposed-private-sentinel', 'host' => '192.0.2.81'])->assertAccepted()->assertDontSee('proposed-private-sentinel');
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    expect($operation->status->value)->toBe('succeeded')->and($operation->result)->toBe(['backend' => 'active']);
    expect($connection->fresh()->password)->toBe('proposed-private-sentinel')->and($connection->fresh()->host)->toBe('192.0.2.81');
    expect($this->socket->sent)->toBe([]);
    $this->assertDatabaseCount('database_execution_intents', 0);
});

it('refuses managed identity changes and non-numeric indexes before admission', function (array $change): void {
    $this->patchJson('/api/v1/database-connections/cache-client', $change)->assertStatus(in_array(array_key_first($change), ['password', 'host'], true) ? 409 : 422);
    expect(Operation::query()->count())->toBe(0)->and($this->connection->fresh()->database)->toBe('0');
})->with([[['password' => 'new']], [['host' => '192.0.2.81']], [['database' => '-1']], [['database' => 'text']]]);

it('keeps a configured AI-router cache unchanged until its native dependency is available', function (bool $switch): void {
    app(ExtensionStore::class)->set('ai-router', $switch);
    app(AiRouterState::class)->enable(
        $this->node->id, $this->connection->slug, 'http://127.0.0.1:8317', 'management', 'read', 'control',
    );
    $this->node->roles()->create(['role' => 'database', 'status' => 'active']);
    $before = $this->connection->refresh()->getRawOriginal();

    $this->patchJson('/api/v1/database-connections/'.$this->connection->slug, ['database' => '2'])
        ->assertServiceUnavailable()->assertJsonPath('error.code', 'operation.kind_unavailable');

    expect($this->connection->refresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('operations', 0);
    $this->assertDatabaseCount('database_execution_intents', 0);
})->with([true, false]);

it('applies two record-only patches in sequence without losing either', function (): void {
    $connection = DatabaseConnection::query()->create(['slug' => 'patch-client', 'driver' => 'redis', 'host' => '192.0.2.80', 'port' => 6379, 'username' => 'original']);
    $this->patchJson('/api/v1/database-connections/patch-client', ['host' => '192.0.2.81'])->assertAccepted();
    $this->patchJson('/api/v1/database-connections/patch-client', ['username' => 'updated'])->assertAccepted();

    expect($connection->refresh()->host)->toBe('192.0.2.81')->and($connection->username)->toBe('updated');
});

it('does not copy the vault login again once the run has ended', function (): void {
    $id = $this->patchJson('/api/v1/database-connections/cache-client', ['database' => '3'])->assertAccepted()->json('data.id');
    $operation = Operation::query()->findOrFail($id);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->postJson('/api/v1/direct-operations/'.$id.'/result', update_callback($this->runId, 'succeeded', update_check("OK\ndatabases\n16\n")))->assertOk();
    $before = count($this->socket->sent);
    expect(app(DatabaseConnectionUpdateProjection::class)->input($operation->fresh())['execution_secrets']['auth']['key'])->toBe('database-update-intent-'.$operation->input['intent_id']);
    expect(count($this->socket->sent))->toBe($before);
});
