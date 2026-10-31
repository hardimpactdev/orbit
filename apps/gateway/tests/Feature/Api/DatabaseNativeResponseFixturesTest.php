<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\Operation;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;

it('records terminal Database Operations for client contract replay', function (string $fixture, string $kind, string $driver): void {
    $this->travelTo(new DateTimeImmutable('2026-10-31T09:00:00+00:00'));
    $node = $this->markAsGateway(Node::query()->create([
        'name' => 'gateway', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.1', 'public_ssh_host' => '192.0.2.1',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $this->withHeader('X-Orbit-Request-Id', fixture_request_id());
    $operation = Operation::query()->create([
        'kind' => $kind, 'target_type' => 'database_server', 'target_id' => 1, 'node_id' => $node->id,
        'input' => ['driver' => $driver], 'status' => 'succeeded', 'sequence' => 3,
        'result' => ['backend' => 'active'], 'started_at' => now(), 'finished_at' => now(),
    ]);

    record_fixture($this->getJson('/api/v1/operations/'.$operation->id)->assertOk()
        ->assertJsonPath('data.kind', $kind)->assertJsonPath('data.result', ['backend' => 'active']),
        $fixture, ShowOperationRequest::class, 'GET /api/v1/operations/{operation}');
})->with([
    ['database-servers/database-server-create/default', 'database_server.create', 'mysql'],
    ['database-servers/database-server-create/valkey', 'database_server.create', 'redis'],
    ['database-servers/database-server-close/default', 'database_server.close', 'redis'],
    ['database-connections/database-create/server', 'database.create', 'mysql'],
    ['database-connections/database-create/postgres', 'database.create', 'pgsql'],
    ['database-connections/database-create/valkey', 'database.create', 'redis'],
    ['database-connections/database-user-create/default', 'database_user.create', 'mysql'],
    ['database-connections/database-user-create/replication', 'database_user.create', 'pgsql'],
]);
