<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\DropOwnedDatabasesAction;
use App\Domain\DatabaseServers\DatabaseDestroyOwnedMaterial;
use App\Domain\DatabaseServers\DatabaseIntentSource;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExecutionIntent;
use App\Models\Node;
use App\Models\Operation;

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
    expect($intent->payload['commands']['database-apply']['protected'])->toBe(['payload']);
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
    $operation = Operation::query()->create(['kind' => 'database.destroy_owned', 'target_type' => 'instance', 'target_id' => $this->instance->id,
        'node_id' => $this->instance->node_id, 'status' => 'running', 'input' => ['intent_id' => $intent->id, 'servers' => $servers, 'participant_node_ids' => [9, 10]]]);
    $material = app(DatabaseDestroyOwnedMaterial::class)->resolve($operation, 9);
    expect((array) $material->value['passwords'])->toBe([42 => 'private-nine']);
    expect(fn () => app(DatabaseDestroyOwnedMaterial::class)->resolve($operation, 11))->toThrow(ResourceOperationException::class);
});

it('serves disjoint owned server passwords through the real shared controller only to their admitted Nodes', function (): void {
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
        'payload' => ['commands' => ['database-apply' => ['protected' => ['payload'], 'input' => ['participant_passwords' => [
            $first->id => [42 => 'private-first-server'], $second->id => [43 => 'private-second-server'],
        ]]]], 'projection' => []]]);
    $run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $operation = Operation::query()->create(['kind' => 'database.destroy_owned', 'target_type' => 'instance', 'target_id' => $this->instance->id,
        'node_id' => $root, 'status' => 'running', 'state' => ['swamp_run_id' => $run], 'input' => [
            'intent_id' => $intent->id, 'servers' => [['server_id' => 42, 'node_id' => $first->id], ['server_id' => 43, 'node_id' => $second->id]], 'sqlite' => [],
            'participant_node_ids' => [$root, $first->id, $second->id],
            'private_bindings' => ['payload' => ['owner_node_id' => $root, 'read_node_ids' => [$first->id, $second->id], 'write_node_ids' => []]],
            'payload' => ['$secret' => 'database.intent', 'id' => $intent->id],
        ]]);
    $path = '/api/v1/direct-operations/'.$operation->id.'/private-material?run_id='.$run;
    foreach ([[$first, '42', 'private-first-server', 'private-second-server'], [$second, '43', 'private-second-server', 'private-first-server']] as [$node, $id, $own, $foreign]) {
        $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
        $this->getJson($path)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJsonStructure(['data' => ['payload' => ['passwords' => [$id]]], 'meta' => ['request_id']])
            ->assertJsonPath('data.payload.passwords.'.$id, $own)->assertDontSee($foreign);
    }
    $this->withServerVariables(['REMOTE_ADDR' => $this->instance->node->wireguard_ip]);
    $this->getJson($path)->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->getJson($path)->assertForbidden();
    request()->setUserResolver(static fn () => null);
    expect(fn () => app(DatabaseIntentSource::class)->resolve($operation, $intent->id, '', 'payload'))->toThrow(ResourceOperationException::class);
    expect(json_encode($operation->fresh()->toArray()))->not->toContain('private-first-server', 'private-second-server');
});
