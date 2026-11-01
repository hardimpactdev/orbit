<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\DropOwnedDatabasesAction;
use App\Actions\DatabaseServers\RecordDatabaseOperationAction;
use App\Domain\DatabaseServers\DatabaseDestroyOwnedMaterial;
use App\Domain\DatabaseServers\DatabaseDestroyOwnedProjection;
use App\Domain\DatabaseServers\DatabaseIntentSource;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExecutionIntent;
use App\Models\Node;
use App\Models\Operation;
use Tests\Support\FakeRoutePublicationSocket;

require_once __DIR__.'/../Support/ServerDatabaseFixtures.php';
uses(ServerDatabaseFixtures::class);

beforeEach(function (): void {
    $this->prepareServerDatabaseFixtures();
    $this->dbNode->update(['architecture' => 'amd64']);
});

it('records one owned intent with placed work and no Gateway child operation', function (): void {
    $connection = DatabaseConnection::query()->create(['slug' => 'proof', 'driver' => 'mysql', 'database_server_id' => $this->server->id,
        'database' => 'proof', 'username' => 'proof', 'owner_instance_id' => $this->instance->id]);
    $prepared = app(DropOwnedDatabasesAction::class)->prepare($this->instance);
    expect($prepared->kind)->toBe('database.destroy_owned');
    expect($prepared->input['connection_ids'])->toBe([$connection->id]);
    expect($prepared->input['servers'][0]['node_id'])->toBe($this->server->node_id);
    expect($prepared->input['servers'][0]['server_id'])->toBe($this->server->id);
    $intent = DatabaseExecutionIntent::recorded($prepared->input['intent_id']);
    expect($intent->payload['commands']['database-apply'])->not->toHaveKey('protected');
    expect($prepared->input)->not->toHaveKeys(['private_bindings', 'payload']);
    expect($prepared->input['servers'][0]['intent']['ssh']['host'])->toBe($this->dbNode->wireguard_ip);
    expect($intent->payload['projection'])->not->toHaveKey('children');
    expect(DatabaseExecutionIntent::query()->count())->toBe(1);
    expect(Operation::query()->count())->toBe(0);
});

it('admits an empty owned set as a native no-op', function (): void {
    $prepared = app(DropOwnedDatabasesAction::class)->prepare($this->instance);
    expect($prepared->input['servers'])->toBe([]);
    expect($prepared->input['sqlite'])->toBe([]);
    expect(Operation::query()->count())->toBe(0);
});

it('selects only the authenticated participants own server credentials', function (): void {
    $servers = [['server_id' => 42, 'node_id' => 9], ['server_id' => 43, 'node_id' => 10]];
    $intent = DatabaseExecutionIntent::query()->create(['target_type' => 'instance', 'target_id' => $this->instance->id, 'node_id' => $this->instance->node_id,
        'payload' => ['commands' => ['database-apply' => ['input' => ['participant_passwords' => [9 => [42 => 'private-nine'], 10 => [43 => 'private-ten']]]]], 'projection' => []]]);
    $operation = Operation::query()->create(['kind' => 'instance.destroy', 'target_type' => 'instance', 'target_id' => $this->instance->id,
        'node_id' => $this->instance->node_id, 'status' => 'running', 'input' => ['direct_destroy' => ['database' => ['intent_id' => $intent->id, 'servers' => $servers]], 'participant_node_ids' => [9, 10]]]);
    $material = app(DatabaseDestroyOwnedMaterial::class)->resolve($operation, 9);
    expect((array) $material->value['passwords'])->toBe([42 => 'private-nine']);
    expect(fn () => app(DatabaseDestroyOwnedMaterial::class)->resolve($operation, 11))->toThrow(ResourceOperationException::class);
});

it('serves disjoint server passwords through the real shared controller for each native parent', function (bool $lifecycle): void {
    $field = $lifecycle ? 'database_material' : 'payload';
    $readers = [];
    foreach ([31, 32] as $suffix) {
        $node = Node::query()->create(['name' => 'database-'.$suffix, 'status' => 'active', 'platform' => 'linux',
            'architecture' => 'amd64', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.'.$suffix, 'public_ssh_host' => '192.0.2.'.$suffix]);
        $node->accessibleNodes()->attach($this->gateway);
        $readers[] = $node;
    }
    [$first, $second] = $readers;
    $root = $this->instance->node_id;
    $intent = DatabaseExecutionIntent::query()->create(['target_type' => 'instance', 'target_id' => $this->instance->id, 'node_id' => $root,
        'payload' => ['commands' => ['database-apply' => ['protected' => [$field], 'input' => ['participant_passwords' => [
            $first->id => [42 => 'private-first-server'], $second->id => [43 => 'private-second-server'],
        ]]]], 'projection' => []]]);
    $run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $servers = [['server_id' => 42, 'node_id' => $first->id], ['server_id' => 43, 'node_id' => $second->id]];
    $public = $lifecycle
        ? ['direct_destroy' => ['database' => ['intent_id' => $intent->id, 'servers' => $servers, 'sqlite' => [], 'connection_ids' => [], 'owner_instance_id' => $this->instance->id]]]
        : ['intent_id' => $intent->id, 'servers' => $servers, 'sqlite' => []];
    $operation = Operation::query()->create(['kind' => $lifecycle ? 'instance.destroy' : 'database.destroy_owned', 'target_type' => 'instance', 'target_id' => $this->instance->id,
        'node_id' => $root, 'status' => 'running', 'state' => ['swamp_run_id' => $run], 'input' => [
            ...$public, 'participant_node_ids' => [$root, $first->id, $second->id],
            ...($lifecycle ? ['private_bindings' => [$field => ['owner_node_id' => $root, 'read_node_ids' => [$first->id, $second->id], 'write_node_ids' => []]],
                $field => ['$secret' => 'database.intent', 'id' => $intent->id]] : []),
        ]]);
    $path = '/api/v1/direct-operations/'.$operation->id.'/private-material?run_id='.$run;
    foreach ([[$first, '42', 'private-first-server', 'private-second-server'], [$second, '43', 'private-second-server', 'private-first-server']] as [$node, $id, $own, $foreign]) {
        $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
        if ($lifecycle) {
            $this->getJson($path)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
                ->assertExactJsonStructure(['data' => [$field => ['passwords' => [$id]]], 'meta' => ['request_id']])
                ->assertJsonPath('data.'.$field.'.passwords.'.$id, $own)->assertDontSee($foreign);
        } else {
            $this->getJson($path)->assertForbidden();
        }
        if (! $lifecycle) {
            $this->getJson('/api/v1/direct-operations/'.$operation->id.'?run_id='.$run)->assertOk()
                ->assertJsonCount(1, 'data.input.servers')
                ->assertJsonPath('data.input.servers.0.server_id', (int) $id)
                ->assertJsonPath('data.cancel_requested', false)
                ->assertDontSee($own)->assertDontSee($foreign);
        }
        if ($lifecycle) {
            $this->getJson('/api/v1/direct-operations/'.$operation->id.'?run_id='.$run)->assertOk()
                ->assertJsonCount(1, 'data.input.direct_destroy.database.servers')
                ->assertJsonPath('data.input.direct_destroy.database.servers.0.server_id', (int) $id)
                ->assertDontSee($own)->assertDontSee($foreign);
        }
    }
    if (! $lifecycle) {
        $this->withServerVariables(['REMOTE_ADDR' => $this->instance->node->wireguard_ip]);
        $this->getJson($path)->assertUnprocessable();
    }
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->getJson($path)->assertForbidden();
    if (! $lifecycle) {
        $publicPath = '/api/v1/direct-operations/'.$operation->id.'?run_id='.$run;
        $this->getJson($publicPath)->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => $first->wireguard_ip]);
        $operation->update(['cancel_requested_at' => now()]);
        $this->getJson($publicPath)->assertOk()->assertJsonPath('data.cancel_requested', true);
        $this->postJson('/api/v1/direct-operations/'.$operation->id.'/result', ['run_id' => $run])->assertForbidden();
        $operation->update(['status' => 'cancelled']);
        $this->getJson($publicPath)->assertConflict();
    }
    request()->setUserResolver(static fn () => null);
    expect(fn () => app(DatabaseIntentSource::class)->resolve($operation, $intent->id, '', $field))->toThrow(ResourceOperationException::class);
    expect(json_encode($operation->fresh()->toArray()))->not->toContain('private-first-server', 'private-second-server');
})->with([false, true]);

it('starts native owned removal with one named password-only copy per server and captured SSH', function (): void {
    config(['swamp.run_token' => 'fake.token']);
    $socket = new FakeRoutePublicationSocket;
    $run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $socket->reply = fn (array $sent): array => $sent['type'] === 'vault.put' ? [['type' => 'vault.put', 'id' => $sent['id'], 'payload' => ['data' => ['vaultName' => $sent['payload']['vaultName'], 'secretKey' => $sent['payload']['key'], 'vaultType' => 'local_encryption']]]] : [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-database-destroy-owned', 'runId' => $run]]];
    app()->instance(WebSocketClient::class, $socket);
    DatabaseConnection::query()->create(['slug' => 'vault-owned', 'driver' => 'mysql', 'database_server_id' => $this->server->id,
        'database' => 'vault_owned', 'username' => 'vault_owned', 'owner_instance_id' => $this->instance->id]);
    $prepared = app(DropOwnedDatabasesAction::class)->prepare($this->instance);
    $this->dbNode->update(['user' => 'later_account']);
    $operation = app(RecordDatabaseOperationAction::class)->start($prepared);
    $copy = collect($socket->sent)->firstWhere('type', 'vault.put');
    expect($copy['payload']['key'])->toBe('database-destroy-owned-intent-'.$prepared->input['intent_id'].'-server-'.$this->server->id);
    expect(json_decode($copy['payload']['value'], true))->toBe(['password' => $this->server->root_password]);
    $start = collect($socket->sent)->firstWhere('type', 'workflow.run');
    expect($start['payload']['inputs']['intent']['servers'][0]['intent']['ssh']['user'])->toBe('orbit');
    expect(json_encode($start))->not->toContain($this->server->root_password);
    expect($operation->state['swamp_run_id'])->toBe($run);
    $operation->update(['status' => 'cancelled']);
    $before = count($socket->sent);
    app(DatabaseDestroyOwnedProjection::class)->input($operation->fresh());
    expect(count($socket->sent))->toBe($before);
});
