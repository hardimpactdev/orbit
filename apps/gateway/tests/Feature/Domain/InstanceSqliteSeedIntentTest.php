<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\PrepareSqliteIntentAction;
use App\Domain\Instances\Sqlite\SqliteSeedPlacement;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

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
            ->assertJsonPath('data.input', ['source' => $input['source'], 'target' => $input['target']])
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
})->with(['database.sqlite_seed', 'database.sqlite_seed_abandon']);

it('refuses an admitted participant without an owned SQLite descriptor', function (): void {
    $run = '9ccecfdf-17d0-4d05-99da-2efac74cdf34';
    $operation = Operation::query()->create(['kind' => 'database.sqlite_export', 'node_id' => $this->gateway->id,
        'target_type' => 'instance', 'target_id' => $this->instance->id, 'status' => 'running',
        'state' => ['swamp_run_id' => $run], 'input' => ['participant_node_ids' => [$this->gateway->id, $this->dbNode->id],
            'source' => ['node_id' => $this->gateway->id]]]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->dbNode->wireguard_ip]);

    $this->getJson('/api/v1/direct-operations/'.$operation->id.'?run_id='.$run)->assertForbidden();
});

it('derives abandon only from the exact recorded transfer seed attempt', function (): void {
    $prepare = app(PrepareSqliteIntentAction::class);
    $source = new SqliteSeedPlacement($this->instance->id, 'development', $this->instance->checkout_path, 'orbit', $this->dbNode);
    $target = new SqliteSeedPlacement($this->instance->id, 'development', $this->instance->checkout_path, 'orbit', $this->gateway, 'transfer-attempt');
    $input = $prepare->seed($source, $target, $this->instance->checkout_path.'/database.sqlite');
    $seed = new Operation(['kind' => 'database.sqlite_seed', 'node_id' => $this->gateway->id,
        'target_type' => 'instance', 'target_id' => $this->instance->id, 'input' => $input]);

    expect($prepare->abandon($this->instance, $seed))->toBe($input);
    $seed->input = [...$input, 'target' => [...$input['target'], 'operation' => str_repeat('f', 64)]];
    expect(fn () => $prepare->abandon($this->instance, $seed))->toThrow(ResourceOperationException::class);
    $this->assertDatabaseCount('operations', 0);
});
