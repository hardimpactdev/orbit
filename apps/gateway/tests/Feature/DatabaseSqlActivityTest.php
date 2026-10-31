<?php

declare(strict_types=1);

use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Activity;
use App\Models\DatabaseConnection;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    if (! OperationKinds::supports('children') || ! OperationKinds::supports('receipts')) {
        $this->markTestSkipped('Database execution awaits Kit B and I enforcement.');
    }
});

it('keeps credential-bearing SQL out of Activity and public Operation input', function (): void {
    $node = Node::query()->create(['name' => 'dev-sql', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'amd64',
        'public_ssh_host' => '192.0.2.10', 'wireguard_ip' => '10.44.0.1', 'user' => 'orbit']);
    NodeRole::query()->create(['node_id' => $node->id, 'role' => 'gateway', 'status' => 'active']);
    $node->accessibleNodes()->attach($node);
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    DatabaseConnection::query()->create(['slug' => 'throwaway', 'driver' => 'sqlite', 'node_id' => $node->id, 'path' => '/tmp/throwaway.sqlite']);
    $sql = "CREATE TABLE synthetic AS SELECT 'private-database-value' AS value";

    config(['swamp.run_token' => 'fake.token']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $sent): array => [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-database-write', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    app()->instance(WebSocketClient::class, $socket);
    $this->postJson('/api/v1/database-connections/throwaway/query', ['sql' => $sql, 'write' => true])->assertAccepted();

    $activity = Activity::query()->where('command', 'database:query')->sole();
    expect($activity->properties?->toArray()['input'])->toBe([]);
    expect(json_encode($activity->toArray()))->not->toContain($sql, 'private-database-value');
    expect(json_encode(Operation::query()->sole()->toArray()))->not->toContain($sql, 'private-database-value');
});
