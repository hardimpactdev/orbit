<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\PrepareDatabaseChildAction;
use App\Actions\DatabaseServers\PurgeDatabaseExecutionIntentsAction;
use App\Actions\DatabaseServers\RecordDatabaseOperationAction;
use App\Domain\DatabaseServers\DatabaseOperationExecution;
use App\Domain\DatabaseServers\PreparedDatabaseOperation;
use App\Domain\Instances\Sqlite\SqliteSeedPlacement;
use App\Domain\Operations\OperationChildIntent;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseExecutionIntent;
use App\Models\Operation;

require_once __DIR__.'/../Support/ServerDatabaseFixtures.php';
require_once __DIR__.'/../Support/DatabaseCreationProofs.php';

uses(ServerDatabaseFixtures::class);
beforeEach(function (): void {
    $this->prepareServerDatabaseFixtures();
    $this->parent = databasePreparationParent($this->instance);
    $this->parent->update(['kind' => 'instance.transfer']);
    $source = new SqliteSeedPlacement($this->instance->id, 'development', $this->instance->checkout_path, 'orbit', $this->dbNode, 'attempt-1');
    $target = new SqliteSeedPlacement($this->instance->id, 'development', '/srv/orbit/dlf/moved', 'orbit', $this->gateway, 'attempt-1');
    $this->seed = app(PrepareDatabaseChildAction::class)->sqliteSeed($source, $target, $source->basePath.'/database.sqlite', $this->parent);
    $this->rollback = Operation::query()->create(['kind' => 'instance.transfer_rollback', 'target_type' => 'instance',
        'target_id' => $this->instance->id, 'node_id' => $this->dbNode->id, 'status' => 'running', 'parent_operation_id' => $this->parent->id, 'input' => []]);
    $this->rollback->forceFill(['parent_operation_id' => $this->parent->id])->save();
});

it('prepares a stable two-Node cleanup from the stored transfer attempt without Node effects', function (): void {
    $prepare = app(PrepareDatabaseChildAction::class);
    $child = $prepare->sqliteSeedAbandon($this->instance, $this->seed['input']['intent_id'], $this->rollback);
    $count = DatabaseExecutionIntent::query()->count();
    expect($prepare->sqliteSeedAbandon($this->instance, $this->seed['input']['intent_id'], $this->rollback))->toBe($child);
    expect(DatabaseExecutionIntent::query()->count())->toBe($count);
    $seed = DatabaseExecutionIntent::recorded($this->seed['input']['intent_id']);
    $intent = DatabaseExecutionIntent::recorded($child['input']['intent_id']);
    $cleanup = $intent->payload['projection']['children'][0];
    $source = DatabaseExecutionIntent::recorded($cleanup['input']['intent_id']);
    expect($child['kind'])->toBe('database.sqlite_seed_abandon');
    expect($child['node_id'])->toBe($seed->node_id);
    expect($intent->parent_operation_id)->toBe($this->rollback->id);
    expect($source->parent_operation_id)->toBe($this->rollback->id);
    expect($intent->payload['commands']['database-apply']['input'])->toBe([
        ...$seed->payload['commands']['database-apply']['input'], 'mode' => 'cleanup', 'expected_size' => 0, 'expected_digest' => '',
    ]);
    $originalSource = DatabaseExecutionIntent::recorded($seed->payload['projection']['seed_attempt']['source_cleanup_intent_id']);
    expect($source->payload['commands']['database-apply']['input'])->toBe($originalSource->payload['commands']['database-apply']['input']);
    expect($cleanup['phase'])->toBe('after');
    expect($cleanup['node_id'])->toBe($this->dbNode->id);
    expect(json_encode($child))->not->toContain('attempt-1', '/tmp/', 'state_dir', 'parent_operation_id', 'password');
    expect($this->admin->sql())->not->toContain('CREATE ', 'DROP ');
});

it('refuses another Instance or transfer parent and never records cleanup', function (string $invalid): void {
    $before = DatabaseExecutionIntent::query()->count();
    if ($invalid === 'instance') {
        $this->rollback->update(['target_id' => $this->instance->id + 1]);
    } else {
        $this->rollback->forceFill(['parent_operation_id' => null])->save();
    }
    expect(fn () => app(PrepareDatabaseChildAction::class)->sqliteSeedAbandon($this->instance, $this->seed['input']['intent_id'], $this->rollback))
        ->toThrow(ResourceOperationException::class);
    expect(DatabaseExecutionIntent::query()->count())->toBe($before);
})->with(['instance', 'transfer']);

it('refuses seed intents without a matching development transfer attempt', function (string $invalid): void {
    $seed = DatabaseExecutionIntent::recorded($this->seed['input']['intent_id']);
    $payload = $seed->payload;
    if ($invalid === 'attempt') {
        unset($payload['projection']['seed_attempt']);
    } elseif ($invalid === 'identity') {
        $payload['projection']['seed_attempt']['operation'] = 'another-attempt';
    } elseif ($invalid === 'production') {
        $payload['commands']['database-apply']['input']['environment'] = 'production';
    } else {
        $source = DatabaseExecutionIntent::recorded($payload['projection']['seed_attempt']['source_cleanup_intent_id']);
        $source->update(['node_id' => $seed->node_id]);
    }
    $seed->update(['payload' => $payload]);
    $before = DatabaseExecutionIntent::query()->count();
    expect(fn () => app(PrepareDatabaseChildAction::class)->sqliteSeedAbandon($this->instance, $seed->id, $this->rollback))
        ->toThrow(ResourceOperationException::class);
    expect(DatabaseExecutionIntent::query()->count())->toBe($before);
})->with(['attempt', 'identity', 'production', 'source']);

it('refuses a development seed without a recorded attempt identity', function (): void {
    $source = new SqliteSeedPlacement($this->instance->id, 'development', $this->instance->checkout_path, 'orbit', $this->dbNode);
    $target = new SqliteSeedPlacement($this->instance->id, 'development', '/srv/orbit/dlf/moved', 'orbit', $this->gateway);
    expect(fn () => app(PrepareDatabaseChildAction::class)->sqliteSeed($source, $target, $source->basePath.'/database.sqlite', $this->parent))
        ->toThrow(ResourceOperationException::class);
});

it('requires target cleanup proof and the exact successful source child before completion', function (): void {
    $child = app(PrepareDatabaseChildAction::class)->sqliteSeedAbandon($this->instance, $this->seed['input']['intent_id'], $this->rollback);
    $operation = Operation::query()->create(['kind' => $child['kind'], 'target_type' => 'instance', 'target_id' => $this->instance->id,
        'node_id' => $child['node_id'], 'status' => 'running', 'input' => $child['input']]);
    $execution = app(DatabaseOperationExecution::class);
    $run = ['key' => 'database-apply', 'step' => 'database-apply', 'transport' => 'agent', 'name' => 'instance.install-sqlite-seed'];
    expect($execution->received($operation, $run, ['data' => ['state' => 'complete']]))->toBe(['error_code' => 'sqlite.seed_cleanup_unproven']);
    $result = $execution->received($operation, $run, ['data' => ['state' => 'cleaned']]);
    expect($result)->toBe(['backend' => 'absent', 'state' => 'cleaned']);
    $operation->update(['state' => ['executor_runs' => ['database-apply' => [...$run, 'result' => $result]], 'steps' => ['database-apply' => $result]]]);
    $operation->steps()->create(['position' => 1, 'name' => 'database-apply', 'status' => 'succeeded', 'resumable' => true, 'sequence' => 1]);
    expect(fn () => $execution->assertEffects($operation))->toThrow(ResourceOperationException::class);
    $planned = DatabaseExecutionIntent::recorded($child['input']['intent_id'])->payload['projection']['children'][0];
    $source = Operation::query()->create(['kind' => $planned['kind'], 'target_type' => 'instance', 'target_id' => $this->instance->id,
        'node_id' => $planned['node_id'], 'parent_operation_id' => $operation->id, 'child_key' => $planned['key'],
        'status' => 'failed', 'input' => $planned['input']]);
    $snapshot = ['intent' => $planned, 'admitted' => ['parent_operation_id' => $operation->id, 'key' => $planned['key'], 'kind' => $planned['kind'],
        'node_id' => $source->node_id, 'target_type' => 'instance', 'target_id' => $this->instance->id, 'input' => $source->input, 'run_constraints' => []]];
    $source->forceFill(['parent_operation_id' => $operation->id, 'child_key' => $planned['key'], 'child_admission' => $snapshot, 'child_fingerprint' => OperationChildIntent::fingerprint($snapshot)])->save();
    expect(fn () => $execution->assertEffects($operation))->toThrow(ResourceOperationException::class);
    $source->update(['status' => 'succeeded']);
    $execution->assertEffects($operation);
    expect($execution->received($source, ['key' => 'database-apply', 'name' => 'instance.prepare-sqlite-seed'], ['data' => []]))
        ->toBe(['backend' => 'absent']);
});

it('keeps the prepared compensation and source cleanup while the transfer is still pending', function (): void {
    $child = app(PrepareDatabaseChildAction::class)->sqliteSeedAbandon($this->instance, $this->seed['input']['intent_id'], $this->rollback);
    $count = DatabaseExecutionIntent::query()->count();
    DatabaseExecutionIntent::query()->update(['created_at' => now()->subDays(2)]);
    expect(app(PurgeDatabaseExecutionIntentsAction::class)->execute())->toBe(0);
    expect(DatabaseExecutionIntent::query()->count())->toBe($count);
    expect(DatabaseExecutionIntent::recorded($this->seed['input']['intent_id'])->payload['projection']['abandon_intent_id'])->toBe($child['input']['intent_id']);
});

it('keeps compensation admission unavailable until the kit enforces cancellation boundaries', function (): void {
    if (OperationKinds::supports('compensations')) {
        $this->markTestSkipped('The shared compensation capability is now enabled.');
    }
    $child = app(PrepareDatabaseChildAction::class)->sqliteSeedAbandon($this->instance, $this->seed['input']['intent_id'], $this->rollback);
    $intent = DatabaseExecutionIntent::recorded($child['input']['intent_id']);
    expect(app(OperationKinds::class)->definition($child['kind']))->toBeNull();
    expect(fn () => app(RecordDatabaseOperationAction::class)->start(new PreparedDatabaseOperation($child['kind'], 'instance', $this->instance->id, $intent->node_id, $child['input'])))
        ->toThrow(ResourceOperationException::class, 'kit execution capabilities');
});

it('refuses cleanup retryably until every recorded seed and export Operation is terminal', function (string $kind, string $status): void {
    $child = app(PrepareDatabaseChildAction::class)->sqliteSeedAbandon($this->instance, $this->seed['input']['intent_id'], $this->rollback);
    $seed = DatabaseExecutionIntent::recorded($this->seed['input']['intent_id']);
    $id = $kind === 'seed' ? $seed->id : $seed->payload['commands']['database-apply']['export_intent_id'];
    $work = Operation::query()->create(['kind' => 'database.sqlite_'.$kind, 'target_type' => 'instance', 'target_id' => $this->instance->id,
        'node_id' => $kind === 'seed' ? $this->gateway->id : $this->dbNode->id, 'status' => $status, 'input' => ['intent_id' => $id]]);
    $operation = new Operation(['kind' => $child['kind'], 'target_type' => 'instance', 'target_id' => $this->instance->id,
        'node_id' => $child['node_id'], 'input' => $child['input']]);
    $execution = app(DatabaseOperationExecution::class);
    $run = $execution->context($operation)['descriptors'][0];

    try {
        $execution->beforeRun($operation, $run);
        $this->fail('Cleanup must wait for seed and export work.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('sqlite.seed_busy');
        expect($exception->status)->toBe(409);
    }
    expect($this->admin->statements)->toBe([]);
    $work->update(['status' => 'cancelled', 'finished_at' => now()]);
    $execution->beforeRun($operation, $run);
})->with([['seed', 'queued'], ['seed', 'running'], ['seed', 'waiting'], ['export', 'queued'], ['export', 'running'], ['export', 'waiting']]);
