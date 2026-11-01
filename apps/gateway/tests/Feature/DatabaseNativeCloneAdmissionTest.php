<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\PrepareDatabaseCloneAction;
use App\Actions\DatabaseServers\RecordDatabaseOperationAction;
use App\Domain\DatabaseServers\DatabaseCloneProjection;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\DatabaseExecutionIntent;
use App\Models\Instance;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeRoutePublicationSocket;

require_once __DIR__.'/../Support/ServerDatabaseFixtures.php';

uses(ServerDatabaseFixtures::class);
beforeEach(function (): void {
    $this->prepareServerDatabaseFixtures();
    $this->dbNode->update(['architecture' => 'amd64']);
    $this->gateway->update(['architecture' => 'amd64']);
});

function nativeDatabaseCloneSource(Instance $target): Instance
{
    return Instance::query()->create(['project_id' => $target->project_id, 'node_id' => $target->node_id,
        'name' => 'source', 'environment' => 'development', 'checkout_path' => '/srv/orbit/dlf/source',
        'source_is_laravel' => true, 'provisioning_step' => 'active', 'status' => 'active']);
}

it('records MySQL source and reserved owner without SQL plans, live probes or child Operations', function (): void {
    $source = nativeDatabaseCloneSource($this->instance);
    $connection = DatabaseConnection::query()->create(['slug' => 'source', 'driver' => 'mysql', 'database_server_id' => $this->server->id,
        'database' => 'source_database', 'username' => 'source_user', 'password' => 'synthetic-source-secret']);
    DatabaseConnectionTarget::query()->create(['instance_id' => $source->id, 'prefix' => 'DB', 'database_connection_id' => $connection->id]);

    $prepared = DB::transaction(fn () => app(PrepareDatabaseCloneAction::class)->prepare($this->instance, $source));
    $intent = DatabaseExecutionIntent::query()->findOrFail($prepared->input['intent_id']);
    $operation = new Operation(['kind' => $prepared->kind, 'target_type' => 'instance', 'target_id' => $this->instance->id,
        'node_id' => $prepared->nodeId, 'status' => 'cancelled', 'input' => [...$prepared->input]]);
    $public = app(DatabaseCloneProjection::class)->input($operation);

    expect($prepared->kind)->toBe('database.clone');
    expect($prepared->nodeId)->toBe($this->server->node_id);
    expect($prepared->input['participant_node_ids'])->toBe([$this->server->node_id]);
    expect($prepared->input)->not->toHaveKey('private_bindings');
    expect($public['execution_secrets']['database_clone']['key'])->toBe('database-clone-intent-'.$intent->id);
    expect($public['intent'])->toMatchArray(['variant' => 'mysql', 'node_id' => $this->server->node_id]);
    expect($public['intent']['mysql'])->toMatchArray(['source_database' => 'source_database']);
    expect(json_encode($public))->not->toContain(SERVER_DATABASE_ROOT_SECRET, 'synthetic-source-secret', 'CREATE ', 'DROP ', 'GRANT ', 'payload');
    expect(array_keys($intent->payload['commands']))->toBe(['database-apply']);
    expect($intent->payload['projection'])->not->toHaveKey('children');
    expect(DatabaseExecutionIntent::query()->count())->toBe(1);
    expect(Operation::query()->count())->toBe(0);
});

it('records one SQLite clone DAG with immutable source and target participants', function (bool $crossNode): void {
    $source = nativeDatabaseCloneSource($this->instance);
    if ($crossNode) {
        $source->update(['node_id' => $this->gateway->id]);
    }
    $connection = DatabaseConnection::query()->create(['slug' => 'source', 'driver' => 'sqlite', 'node_id' => $source->node_id,
        'path' => $source->checkout_path.'/database/database.sqlite']);
    DatabaseConnectionTarget::query()->create(['instance_id' => $source->id, 'prefix' => 'DB', 'database_connection_id' => $connection->id]);

    $prepared = DB::transaction(fn () => app(PrepareDatabaseCloneAction::class)->prepare($this->instance, $source));
    $intent = DatabaseExecutionIntent::query()->findOrFail($prepared->input['intent_id']);
    $input = $intent->payload['commands']['database-apply']['input'];

    expect($input['variant'])->toBe($crossNode ? 'sqlite_remote' : 'sqlite_local');
    expect($input['source'])->toMatchArray(['node_id' => $source->node_id, 'checkout' => $source->checkout_path, 'source' => $connection->path]);
    expect($input['target'])->toMatchArray(['node_id' => $this->instance->node_id, 'checkout' => $this->instance->checkout_path,
        'target' => $this->instance->checkout_path.'/database/database.sqlite']);
    expect($prepared->input['participant_node_ids'])->toBe(array_values(array_unique([$this->instance->node_id, $source->node_id])));
    expect($prepared->input)->not->toHaveKey('private_bindings');
    expect($intent->payload['projection'])->not->toHaveKey('children');
    expect(DatabaseExecutionIntent::query()->count())->toBe(1);
    expect(Operation::query()->count())->toBe(0);
    if ($crossNode) {
        $run = 'b588ad1f-cc12-4b9a-b207-1538e4b3964a';
        $operation = Operation::query()->create(['kind' => $prepared->kind, 'target_type' => 'instance', 'target_id' => $this->instance->id,
            'node_id' => $prepared->nodeId, 'input' => $prepared->input, 'status' => 'running', 'state' => ['swamp_run_id' => $run]]);
        $this->withServerVariables(['REMOTE_ADDR' => $source->node->wireguard_ip]);
        $this->getJson('/api/v1/direct-operations/'.$operation->id.'?run_id='.$run)->assertOk()
            ->assertJsonPath('data.input', $input)->assertJsonPath('data.cancel_requested', false);
        $operation->update(['cancel_requested_at' => now()]);
        $this->getJson('/api/v1/direct-operations/'.$operation->id.'?run_id='.$run)->assertOk()
            ->assertJsonPath('data.cancel_requested', true);
        $this->postJson('/api/v1/direct-operations/'.$operation->id.'/result', ['run_id' => $run])->assertForbidden();
    }
})->with([false, true]);

it('starts clone with captured SSH and a named execution copy without Gateway private reads', function (): void {
    config(['swamp.run_token' => 'fake.token']);
    $socket = new FakeRoutePublicationSocket;
    $run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $socket->reply = fn (array $sent): array => $sent['type'] === 'vault.put' ? [['type' => 'vault.put', 'id' => $sent['id'], 'payload' => ['data' => ['vaultName' => $sent['payload']['vaultName'], 'secretKey' => $sent['payload']['key'], 'vaultType' => 'local_encryption']]]] : [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-database-clone', 'runId' => $run]]];
    app()->instance(WebSocketClient::class, $socket);
    $source = nativeDatabaseCloneSource($this->instance);
    $connection = DatabaseConnection::query()->create(['slug' => 'source', 'driver' => 'mysql', 'database_server_id' => $this->server->id,
        'database' => 'source_database', 'username' => 'source_user', 'password' => 'synthetic-source-secret']);
    DatabaseConnectionTarget::query()->create(['instance_id' => $source->id, 'prefix' => 'DB', 'database_connection_id' => $connection->id]);
    $prepared = app(PrepareDatabaseCloneAction::class)->prepare($this->instance, $source);
    $this->dbNode->update(['user' => 'later_account']);
    $operation = app(RecordDatabaseOperationAction::class)->start($prepared);
    $copy = collect($socket->sent)->firstWhere('type', 'vault.put');
    expect($copy['payload']['key'])->toBe('database-clone-intent-'.$prepared->input['intent_id']);
    expect(json_decode($copy['payload']['value'], true)['password'])->toBe($this->server->root_password);
    $start = collect($socket->sent)->firstWhere('type', 'workflow.run');
    expect($start['payload']['inputs']['intent']['target_ssh']['user'])->toBe('orbit');
    expect(json_encode($start))->not->toContain($this->server->root_password, 'synthetic-source-secret');
    expect($operation->state['swamp_run_id'])->toBe($run);
    $operation->update(['status' => 'cancelled']);
    $before = count($socket->sent);
    app(DatabaseCloneProjection::class)->input($operation->fresh());
    expect(count($socket->sent))->toBe($before);
});
