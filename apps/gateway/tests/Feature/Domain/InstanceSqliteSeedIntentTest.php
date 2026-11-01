<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\PrepareSqliteIntentAction;
use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\Sqlite\SqliteSeedPlacement;
use App\Domain\Operations\DirectOperation;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Operation;
use Tests\Support\FakeRoutePublicationSocket;

require_once __DIR__.'/../../Support/ServerDatabaseFixtures.php';
uses(ServerDatabaseFixtures::class);

beforeEach(function (): void {
    $this->prepareServerDatabaseFixtures();
    $this->dbNode->accessibleNodes()->attach($this->gateway);
});

it('serves exact SQLite descriptors and fresh cancellation only to admitted source and target Nodes', function (string $kind): void {
    $source = new SqliteSeedPlacement($this->instance->id, 'development', $this->instance->checkout_path, 'orbit', $this->dbNode);
    $target = new SqliteSeedPlacement($this->instance->id, 'development', $this->instance->checkout_path, 'orbit', $this->gateway, 'transfer-attempt');
    $input = app(PrepareSqliteIntentAction::class)->seed($source, $target, $this->instance->checkout_path.'/database.sqlite');
    $run = '9ccecfdf-17d0-4d05-99da-2efac74cdf34';
    $operation = Operation::query()->create(['kind' => $kind, 'node_id' => $this->gateway->id,
        'target_type' => 'instance', 'target_id' => $this->instance->id, 'status' => 'running',
        'state' => ['swamp_run_id' => $run], 'input' => $input]);
    $path = '/api/v1/direct-operations/'.$operation->id;

    foreach ([$this->dbNode, $this->gateway] as $node) {
        $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
        $this->getJson($path.'?run_id='.$run)->assertOk()
            ->assertJsonPath($node->id === $this->gateway->id ? 'data.input.intent.source' : 'data.input.source', $input['source'])
            ->assertJsonPath($node->id === $this->gateway->id ? 'data.input.intent.target' : 'data.input.target', $input['target'])
            ->assertJsonPath('data.node_id', $this->gateway->id)->assertJsonPath('data.cancel_requested', false);
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
})->with(['database.sqlite_seed']);

it('refuses an admitted participant without an owned SQLite descriptor', function (): void {
    $run = '9ccecfdf-17d0-4d05-99da-2efac74cdf34';
    $operation = Operation::query()->create(['kind' => 'database.sqlite_export', 'node_id' => $this->gateway->id,
        'target_type' => 'instance', 'target_id' => $this->instance->id, 'status' => 'running',
        'state' => ['swamp_run_id' => $run], 'input' => ['participant_node_ids' => [$this->gateway->id, $this->dbNode->id],
            'source' => ['node_id' => $this->gateway->id]]]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->dbNode->wireguard_ip]);

    $this->getJson('/api/v1/direct-operations/'.$operation->id.'?run_id='.$run)->assertForbidden();
});

it('starts standalone SQLite work with full public SSH intent and no execution copy', function (string $kind): void {
    config(['swamp.run_token' => 'fake.token']);
    $source = new SqliteSeedPlacement($this->instance->id, 'development', $this->instance->checkout_path, 'orbit', $this->dbNode);
    $target = new SqliteSeedPlacement($this->instance->id, 'development', $this->instance->checkout_path, 'orbit', $this->gateway, 'native-proof');
    $input = app(PrepareSqliteIntentAction::class)->seed($source, $target, $this->instance->checkout_path.'/database.sqlite');
    $nodeId = $kind === 'database.sqlite_export' ? $this->dbNode->id : $this->gateway->id;
    if ($kind === 'database.sqlite_export') {
        unset($input['target']);
    }
    $socket = new FakeRoutePublicationSocket;
    $workflow = app(DirectOperation::class)->definition($kind)['workflow'];
    $run = '9ccecfdf-17d0-4d05-99da-2efac74cdf34';
    $socket->reply = fn (array $sent): array => [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => $workflow, 'runId' => $run]]];
    app()->instance(WebSocketClient::class, $socket);
    $operation = app(StartOperationAction::class)->execute($kind, 'instance', $this->instance->id, $nodeId, $input, 'test', null);
    expect($socket->sent)->toHaveCount(1);
    $public = $socket->sent[0]['payload']['inputs'];
    expect($public['intent']['source'])->toBe($input['source']);
    expect($public['intent']['source_ssh'])->toMatchArray(['host' => $this->dbNode->wireguard_ip, 'user' => 'orbit']);
    expect(json_encode($public['execution_secrets']))->toBe('{}');
    expect($operation->fresh()->state['swamp_run_id'])->toBe($run);
    $this->dbNode->update(['user' => 'later_account']);
    expect($public['intent']['source_ssh']['user'])->toBe('orbit');
    expect(app(DirectOperation::class)->definition($kind)['terminal_result'])->toBe(['job' => 'sqlite', 'step' => 'result']);
})->with(['database.sqlite_export', 'database.sqlite_seed']);
