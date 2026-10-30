<?php

declare(strict_types=1);

use App\Actions\DatabaseServers\RecordDatabaseOperationAction;
use App\Domain\DatabaseServers\DatabaseIntentSource;
use App\Domain\DatabaseServers\DatabaseOperationExecution;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationSecrets;
use App\Domain\Operations\SwampOperationExecutor;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentSchemas;
use App\Models\DatabaseExecutionIntent;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Arr;
use Tests\Support\FakeAgentCommandRunner;

function databaseContractOperation(): Operation
{
    $node = Node::query()->create(['name' => 'dev-db', 'platform' => 'linux', 'status' => 'active', 'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '192.0.2.2', 'user' => 'orbit']);

    $prepared = app(RecordDatabaseOperationAction::class)->prepare('database.create', 'database_server', 123, $node->id,
        ['driver' => 'mysql'], ['database-apply' => ['name' => 'database.server-admin', 'input' => [
            'operation' => 'execute', 'container' => 'orbit-process-1-dev', 'password' => 'synthetic-admin-password',
            'password_variable' => 'MYSQL_PWD', 'client' => ['mysql'], 'sql' => 'CREATE DATABASE example;',
        ], 'protected' => ['password', 'sql'], 'expect_empty' => true]]);

    return Operation::query()->create(['kind' => $prepared->kind, 'target_type' => $prepared->targetType, 'target_id' => $prepared->targetId, 'node_id' => $prepared->nodeId, 'input' => $prepared->input, 'status' => 'queued']);

}

it('exposes only protected references while hydrated commands match the installed agent schema', function (): void {
    $operation = databaseContractOperation();
    $execution = app(DatabaseOperationExecution::class);
    $source = app(DatabaseIntentSource::class);

    $context = $execution->context($operation);
    $descriptor = $context['descriptors'][0];
    expect($descriptor['input']['password'])->toBe(['$secret' => 'database.intent', 'id' => $operation->input['intent_id']]);
    expect(json_encode($context))->not->toContain('synthetic-admin-password', 'CREATE DATABASE example');
    $input = $descriptor['input'];
    foreach (['password', 'sql'] as $parameter) {
        Arr::set($input, $parameter, $source->resolve($operation, $operation->input['intent_id'], $descriptor['key'], $parameter)->value);
    }
    AgentSchemas::assertInput('database.server-admin', $input);
    expect($input['password'])->toBe('synthetic-admin-password');
});

it('refuses another target and another Node before resolving any private value', function (): void {
    $operation = databaseContractOperation();
    $source = app(DatabaseIntentSource::class);
    foreach (['target_id', 'node_id'] as $field) {
        $foreign = clone $operation;
        $foreign->{$field}++;
        expect($source->ownerId($foreign))->toBeNull();
        expect(fn () => $source->resolve($foreign, $operation->input['intent_id'], 'database-apply', 'password'))
            ->toThrow(ResourceOperationException::class);
    }
});

it('refuses unlisted commands, programs and effect keys in the exact input binder', function (): void {
    $operation = databaseContractOperation();
    $execution = app(DatabaseOperationExecution::class);
    foreach ([['key' => 'other', 'transport' => 'agent', 'name' => 'database.server-admin'],
        ['key' => 'database-apply', 'transport' => 'agent', 'name' => 'process.remove'],
        ['key' => 'database-apply', 'transport' => 'remote_program', 'name' => 'database.write']] as $run) {
        expect(fn () => $execution->input($operation, [...$run, 'worker' => 'proof', 'input' => []]))->toThrow(ResourceOperationException::class);
    }
});

it('keeps raw SQL output out of results and refuses a nonempty preflight', function (): void {
    $operation = databaseContractOperation();
    $execution = app(DatabaseOperationExecution::class);
    $run = ['key' => 'database-apply', 'transport' => 'agent', 'name' => 'database.server-admin', 'worker' => 'proof', 'input' => []];

    expect($execution->received($operation, $run, ['output' => "foreign_database\n", 'stderr' => 'unsafe native diagnostic']))
        ->toBe(['error_code' => 'database.name_conflict']);
    expect($execution->received($operation, $run, ['output' => '']))->toBe(['backend' => 'active']);
});

it('rejects a forged step and requires the recorded named run before projecting completion', function (): void {
    $operation = databaseContractOperation();
    $execution = app(DatabaseOperationExecution::class);
    $operation->steps()->create(['name' => 'database-apply', 'position' => 1, 'status' => 'succeeded', 'resumable' => true, 'sequence' => 1]);
    $operation->state = ['steps' => ['database-apply' => ['backend' => 'active']]];
    expect(fn () => $execution->assertCompletion($operation, OperationOutcome::succeeded(['backend' => 'active'])))
        ->toThrow(ResourceOperationException::class);

    $operation->state = [...$operation->state, 'executor_runs' => ['database-apply' => [
        'transport' => 'agent', 'name' => 'database.server-admin', 'step' => 'database-apply', 'result' => ['backend' => 'active'],
    ]]];
    $execution->assertCompletion($operation, OperationOutcome::succeeded(['backend' => 'active']));
    expect($operation->steps()->count())->toBe(1);
});

it('selects the copy allowlist variant before validating protected parameters', function (): void {
    $operation = databaseContractOperation();
    $operation->kind = 'database.clone';
    $intent = DatabaseExecutionIntent::query()->findOrFail($operation->input['intent_id']);
    $intent->update(['payload' => ['commands' => ['database-apply' => ['name' => 'database.server-admin',
        'input' => ['operation' => 'copy', 'container' => 'orbit-process-1-dev', 'password' => 'synthetic-admin-password', 'source' => 'source_db', 'target' => 'target_db'],
        'protected' => ['password'], 'resumable' => false]], 'projection' => []]]);
    $descriptor = app(DatabaseOperationExecution::class)->context($operation)['descriptors'][0];
    $run = ['worker' => 'proof', 'key' => $descriptor['key'], 'transport' => $descriptor['transport'], 'name' => $descriptor['name'], 'input' => $descriptor['input']];
    $spec = app(OperationExecutionContract::class)->authorizeRun($operation, $run);

    expect($spec['input_values'])->toBe(['operation' => 'copy']);
    expect($spec['secrets'])->toBe(['password' => 'database.intent']);
    expect(app(OperationSecrets::class)->hydrate($operation, $descriptor['key'], $descriptor['input'], $spec['secrets'])['input']['password'])->toBe('synthetic-admin-password');
});

it('refuses invented metadata on failed completion', function (): void {
    $operation = databaseContractOperation();
    expect(fn () => app(DatabaseOperationExecution::class)->assertCompletion($operation, OperationOutcome::failed('database.in_use', ['backend' => 'absent', 'row_count' => 99])))
        ->toThrow(ResourceOperationException::class);
});

it('runs through shared hydration and stores only references and redacted receipts', function (): void {
    $operation = databaseContractOperation();
    $operation->update(['kind' => 'database_user.create']);
    $agents = new FakeAgentCommandRunner()->answer('database.server-admin', ['output' => '']);
    app()->instance(AgentCommandRunner::class, $agents);
    $executor = app(SwampOperationExecutor::class);
    $operation = $executor->claim('database_user.create', 'proof', $operation->node_id);
    $descriptor = app(DatabaseOperationExecution::class)->context($operation)['descriptors'][0];
    $result = $executor->run($operation, ['worker' => 'proof', 'key' => $descriptor['key'], 'transport' => $descriptor['transport'], 'name' => $descriptor['name'], 'input' => $descriptor['input']], $operation->node_id);
    $executor->step($operation, ['worker' => 'proof', 'key' => 'finish', 'name' => 'database-apply', 'status' => 'succeeded', 'result' => $result], $operation->node_id);
    $proof = $executor->run($operation, ['worker' => 'proof', 'key' => 'database-complete', 'transport' => 'gateway', 'name' => 'database.proven', 'input' => ['intent_id' => $operation->input['intent_id'], 'children' => []]], $operation->node_id);
    $executor->step($operation, ['worker' => 'proof', 'key' => 'proof-finish', 'name' => 'database-complete', 'status' => 'succeeded', 'result' => $proof], $operation->node_id);
    $executor->complete($operation, ['worker' => 'proof', 'status' => 'succeeded', 'result' => $result], $operation->node_id);

    expect($agents->calls[0]['input']['password'])->toBe('synthetic-admin-password');
    expect($agents->calls[0]['node'])->toBe('dev-db');
    expect(json_encode($operation->fresh()->toArray()))->not->toContain('synthetic-admin-password', 'CREATE DATABASE example;');

});
