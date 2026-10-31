<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\DatabaseExecutionIntent;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Operation;
use Tests\Support\FakeRoutePublicationSocket;

it('retains native interruption and records server failure without deleting its state', function (string $kind, string $initial): void {
    $node = Node::query()->create(['name' => 'interrupted-database', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9']);
    $server = DatabaseServer::query()->create(['slug' => 'interrupted-proof', 'driver' => 'redis', 'node_id' => $node->id,
        'tag' => '8.0', 'port' => 6379, 'root_password' => 'fixture-root', 'status' => $initial]);
    $intent = DatabaseExecutionIntent::query()->create(['target_type' => 'database_server', 'target_id' => $server->id,
        'node_id' => $node->id, 'payload' => ['admission' => ['input' => []]]]);
    $run = '8e3475ec-5187-42cf-8993-0e50c008c365';
    $operation = Operation::query()->create(['kind' => $kind, 'target_type' => 'database_server', 'target_id' => $server->id,
        'node_id' => $node->id, 'input' => ['intent_id' => $intent->id], 'state' => ['swamp_run_id' => $run],
        'status' => OperationStatus::Running, 'lock_key' => 'server-'.$server->id, 'sequence' => 1, 'started_at' => now()]);
    $definition = app(DirectOperation::class)->definition($kind);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => [
        'id' => $run, 'workflowName' => $definition['workflow'], 'status' => 'interrupted',
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $node->id, 'kind' => $kind],
        'startedAt' => '2026-11-01T00:00:00Z', 'completedAt' => '2026-11-01T00:00:01Z',
        'jobs' => [['name' => $definition['terminal_result']['job'], 'steps' => [['name' => 'result', 'status' => 'pending']]]],
    ]]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.read_token' => 'fixture.reader', 'swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/public/ca.pem']);

    $projected = app(SwampCommandStep::class)->projectTerminal($operation, ['id' => $run, 'status' => 'interrupted'], $definition['terminal_result']);

    expect($projected->status)->toBe(OperationStatus::Interrupted);
    expect($projected->lock_key)->toBeNull();
    expect($projected->error_code)->toBe('operation.native_failed');
    expect($server->fresh()->status->value)->toBe('failed');
    expect($server->fresh()->error_code)->toBe('operation.native_failed');
    expect($server->fresh()->failed_step)->toBe('database-apply');
    expect(DatabaseServer::query()->count())->toBe(1);
})->with([['database_server.create', 'provisioning'], ['database_server.destroy', 'removing']]);
