<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExecutionIntent;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Operation;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->gateway = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']));
    $this->node = Node::query()->create(['name' => 'database', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9']);
    $this->node->accessibleNodes()->attach($this->gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    config(['swamp.run_token' => 'fake.token']);
    $this->runId = 'e1589ed9-996b-4c9c-aa75-7749ef519210';
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $sent): array => [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-database-connection-update', 'runId' => $this->runId]]];
    app()->instance(WebSocketClient::class, $socket);
    $this->server = DatabaseServer::query()->create(['slug' => 'cache', 'driver' => 'redis', 'node_id' => $this->node->id, 'port' => 6379, 'tag' => '8.1', 'root_password' => 'private-admin-sentinel', 'status' => 'active']);
    $this->connection = DatabaseConnection::query()->create(['slug' => 'cache-client', 'driver' => 'redis', 'database_server_id' => $this->server->id, 'node_id' => $this->node->id, 'host' => $this->node->wireguard_ip, 'port' => 6379, 'database' => '0', 'username' => 'cache', 'password' => 'private-client-sentinel']);
});

function update_callback(string $runId, string $status, array $result = []): array
{
    return ['run_id' => $runId, 'status' => $status, 'started_at' => '2026-10-31T09:00:00.000Z', 'finished_at' => '2026-10-31T09:00:01.000Z', 'result' => $result, 'error_code' => $result['error_code'] ?? null];
}

it('records an update without mutating the connection and exposes only declared root material', function (): void {
    $response = $this->patchJson('/api/v1/database-connections/cache-client', ['database' => '3'])->assertAccepted()->assertJsonPath('data.kind', 'database.update');
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    expect($operation->node_id)->toBe($this->node->id)->and($this->connection->fresh()->database)->toBe('0');
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $path = '/api/v1/direct-operations/'.$operation->id;
    $this->getJson($path.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.input.index', '3')->assertDontSee('private-admin-sentinel')->assertDontSee('private-client-sentinel');
    $this->getJson($path.'/private-material?run_id='.$this->runId)->assertOk()->assertJsonPath('data.password', 'private-admin-sentinel')->assertDontSee('private-client-sentinel');
    $this->postJson($path.'/result', update_callback($this->runId, 'succeeded', ['backend' => 'active', 'password' => 'discarded-native-secret']))->assertOk()->assertDontSee('discarded-native-secret');
    expect($this->connection->fresh()->database)->toBe('3')->and($operation->fresh()->result)->toBe(['backend' => 'active']);
});

it('leaves an out-of-range proposed index unchanged on native refusal', function (): void {
    $id = $this->patchJson('/api/v1/database-connections/cache-client', ['database' => '99'])->assertAccepted()->json('data.id');
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->postJson('/api/v1/direct-operations/'.$id.'/result', update_callback($this->runId, 'failed', ['error_code' => 'database.index_invalid']))->assertOk();
    expect($this->connection->fresh()->database)->toBe('0')->and(Operation::query()->findOrFail($id)->error_code)->toBe('database.index_invalid');
});

it('keeps proposed passwords encrypted and projects immutable record-only updates once', function (): void {
    $connection = DatabaseConnection::query()->create(['slug' => 'external', 'driver' => 'mysql', 'host' => '192.0.2.80', 'database' => 'app', 'username' => 'app', 'password' => 'old-private']);
    $id = $this->patchJson('/api/v1/database-connections/external', ['password' => 'proposed-private-sentinel', 'host' => '192.0.2.81'])->assertAccepted()->assertDontSee('proposed-private-sentinel')->json('data.id');
    $operation = Operation::query()->findOrFail($id);
    $intent = DatabaseExecutionIntent::recorded($operation->input['intent_id']);
    expect($intent->getRawOriginal('payload'))->not->toContain('proposed-private-sentinel')->and($connection->fresh()->password)->toBe('old-private');
    $path = '/api/v1/direct-operations/'.$id;
    $this->getJson($path.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.input', ['validate_index' => false])->assertDontSee('proposed-private-sentinel');
    $this->postJson($path.'/result', update_callback($this->runId, 'succeeded', ['backend' => 'active']))->assertOk();
    expect($connection->fresh()->password)->toBe('proposed-private-sentinel')->and($connection->fresh()->host)->toBe('192.0.2.81');
    $connection->update(['host' => '192.0.2.82']);
    $this->postJson($path.'/result', update_callback($this->runId, 'succeeded', ['backend' => 'active']))->assertOk();
    expect($connection->fresh()->host)->toBe('192.0.2.82');
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

it('preserves disjoint patches when two admitted updates finish in sequence', function (): void {
    $connection = DatabaseConnection::query()->create(['slug' => 'patch-client', 'driver' => 'redis', 'host' => '192.0.2.80', 'port' => 6379, 'username' => 'original']);
    $first = $this->patchJson('/api/v1/database-connections/patch-client', ['host' => '192.0.2.81'])->assertAccepted()->json('data.id');
    $second = $this->patchJson('/api/v1/database-connections/patch-client', ['username' => 'updated'])->assertAccepted()->json('data.id');

    foreach ([$first, $second] as $id) {
        $this->postJson('/api/v1/direct-operations/'.$id.'/result', update_callback($this->runId, 'succeeded', ['backend' => 'active']))->assertOk();
    }

    expect($connection->refresh()->host)->toBe('192.0.2.81')->and($connection->username)->toBe('updated');
});
