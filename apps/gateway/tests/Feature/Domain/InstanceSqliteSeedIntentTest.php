<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\PrepareSqliteIntentAction;
use App\Domain\Instances\Sqlite\SqliteSeedPlacement;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Node;
use App\Models\Operation;
use Tests\Support\FakeRoutePublicationSocket;

require_once __DIR__.'/../../Support/ServerDatabaseFixtures.php';
uses(ServerDatabaseFixtures::class);

beforeEach(function (): void {
    $this->prepareServerDatabaseFixtures();
    $this->executionNode = Node::query()->forceCreate(['id' => 14, 'name' => 'seed-target', 'public_ssh_host' => '192.0.2.14',
        'wireguard_ip' => '10.44.0.14', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active']);
    $this->executionNode->accessibleNodes()->attach($this->gateway);
    $this->dbNode->accessibleNodes()->attach($this->gateway);
});

it('serves exact SQLite descriptors and fresh cancellation only to admitted source and target Nodes', function (string $kind): void {
    $source = new SqliteSeedPlacement($this->instance->id, 'development', $this->instance->checkout_path, 'orbit', $this->dbNode);
    $target = new SqliteSeedPlacement($this->instance->id, 'development', $this->instance->checkout_path, 'orbit', $this->executionNode, 'transfer-attempt');
    $input = app(PrepareSqliteIntentAction::class)->seed($source, $target, $this->instance->checkout_path.'/database.sqlite');
    $run = '9ccecfdf-17d0-4d05-99da-2efac74cdf34';
    $operation = Operation::query()->create(['kind' => $kind, 'node_id' => $this->executionNode->id,
        'target_type' => 'instance', 'target_id' => $this->instance->id, 'status' => 'running',
        'state' => ['swamp_run_id' => $run], 'input' => $input]);
    $path = '/api/v1/direct-operations/'.$operation->id;

    foreach ([$this->dbNode, $this->executionNode] as $node) {
        $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
        $this->getJson($path.'?run_id='.$run)->assertOk()
            ->assertJsonPath($kind === 'database.sqlite_seed_abandon' ? 'data.input.intent' : 'data.input', ['source' => $input['source'], 'target' => $input['target']])
            ->assertJsonPath('data.node_id', $this->executionNode->id)->assertJsonPath('data.cancel_requested', false);
    }
    $this->withServerVariables(['REMOTE_ADDR' => $this->dbNode->wireguard_ip]);
    $operation->update(['cancel_requested_at' => now()]);
    $this->getJson($path.'?run_id='.$run)->assertOk()->assertJsonPath('data.cancel_requested', true);
    $this->postJson($path.'/result', ['run_id' => $run])->assertForbidden();
    $this->getJson($path.'?run_id=563d0a2f-a69b-4ed5-875c-5664378d5615')->assertConflict();
    $operation->update(['status' => 'cancelled']);
    $this->getJson($path.'?run_id='.$run)->assertConflict();
    $this->assertDatabaseCount('operations', 1);
    $this->assertDatabaseCount('database_execution_intents', 0);
})->with(['database.sqlite_seed', 'database.sqlite_seed_abandon']);

it('refuses an admitted participant without an owned SQLite descriptor', function (): void {
    $run = '9ccecfdf-17d0-4d05-99da-2efac74cdf34';
    $operation = Operation::query()->create(['kind' => 'database.sqlite_export', 'node_id' => $this->executionNode->id,
        'target_type' => 'instance', 'target_id' => $this->instance->id, 'status' => 'running',
        'state' => ['swamp_run_id' => $run], 'input' => ['participant_node_ids' => [$this->executionNode->id, $this->dbNode->id],
            'source' => ['node_id' => $this->executionNode->id]]]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->dbNode->wireguard_ip]);

    $this->getJson('/api/v1/direct-operations/'.$operation->id.'?run_id='.$run)->assertForbidden();
});

it('derives abandon only from the exact recorded transfer seed attempt', function (): void {
    $prepare = app(PrepareSqliteIntentAction::class);
    $source = new SqliteSeedPlacement($this->instance->id, 'development', $this->instance->checkout_path, 'orbit', $this->dbNode);
    $target = new SqliteSeedPlacement($this->instance->id, 'development', $this->instance->checkout_path, 'orbit', $this->executionNode, 'transfer-attempt');
    $input = $prepare->seed($source, $target, $this->instance->checkout_path.'/database.sqlite');
    $seed = new Operation(['kind' => 'database.sqlite_seed', 'node_id' => $this->executionNode->id,
        'target_type' => 'instance', 'target_id' => $this->instance->id, 'input' => $input]);

    expect($prepare->abandon($this->instance, $seed))->toBe($input);
    $seed->input = [...$input, 'target' => [...$input['target'], 'operation' => str_repeat('f', 64)]];
    expect(fn () => $prepare->abandon($this->instance, $seed))->toThrow(ResourceOperationException::class);
    $this->assertDatabaseCount('operations', 0);
});

it('submits captured abandon descriptors without a vault copy and projects one terminal result', function (): void {
    $source = new SqliteSeedPlacement($this->instance->id, 'development', $this->instance->checkout_path, 'orbit', $this->dbNode);
    $target = new SqliteSeedPlacement($this->instance->id, 'development', $this->instance->checkout_path, 'orbit', $this->executionNode, 'transfer-attempt');
    $input = app(PrepareSqliteIntentAction::class)->seed($source, $target, $this->instance->checkout_path.'/database.sqlite');
    $operation = Operation::query()->create(['kind' => 'database.sqlite_seed_abandon', 'node_id' => $this->executionNode->id,
        'target_type' => 'instance', 'target_id' => $this->instance->id, 'status' => 'queued', 'input' => [...$input, 'not_for_workflow' => 'private-sentinel']]);
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'fake.token', 'swamp.ca_path' => '/public/ca.pem']);

    app(StartSwampRun::class)->start($operation);
    $sent = $socket->sent[0]['payload']['inputs'];
    expect($sent['intent'])->toBe(['source' => $input['source'], 'target' => $input['target']]);
    expect((array) $sent['execution_secrets'])->toBe([]);
    expect(json_encode($sent))->not->toContain('private-sentinel', 'agent_sha256');
    $run = $operation->fresh()->state['swamp_run_id'];
    $history = ['id' => $run, 'workflowName' => 'operation-database-sqlite-seed-abandon', 'status' => 'succeeded', 'inputs' => $sent,
        'startedAt' => '2026-10-31T14:00:00Z', 'completedAt' => '2026-10-31T14:00:01Z',
        'jobs' => [['name' => 'abandon', 'steps' => [['name' => 'result', 'outputs' => ['stdout' => '{"backend":"absent"}']]]]],
    ];
    $socket->reply = static fn (array $request): array => [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => $history]]];
    $selector = app(DirectOperation::class)->definition('database.sqlite_seed_abandon')['terminal_result'];
    $projected = app(SwampCommandStep::class)->projectTerminal($operation, ['id' => $run, 'status' => 'succeeded'], $selector);
    app(SwampCommandStep::class)->projectTerminal($projected, ['id' => $run, 'status' => 'succeeded'], $selector);

    expect($projected->result)->toBe(['backend' => 'absent']);
    expect(array_column($socket->sent, 'type'))->toBe(['workflow.run', 'workflow.history.get']);
    $this->assertModelExists($this->instance);
});
