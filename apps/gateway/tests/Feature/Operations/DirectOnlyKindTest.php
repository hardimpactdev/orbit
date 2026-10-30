<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\OperationExecutors;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationRegistryCheck;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Operations\SwampOperationExecutor;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

beforeEach(function (): void {
    $this->definition = ['executor' => 'swamp', 'result_fields' => ['ready']];
    $this->kinds = new OperationKinds(app(), ['route.caddy_converge' => $this->definition, 'test.undeclared' => $this->definition]);
    app()->instance(OperationKinds::class, $this->kinds);
});

it('resolves a direct-only workflow and projection with no legacy completion or run contract', function (): void {
    expect(app(OperationRegistryCheck::class)->execute(['route.caddy_converge' => $this->definition]))->toBe(1);
    expect($this->kinds->directOnly('route.caddy_converge'))->toBeTrue();
    expect(app(OperationExecutors::class)->completion('route.caddy_converge'))->toBeNull();
});

it('refuses a direct-only kind without a matching direct workflow declaration', function (): void {
    expect(fn () => app(OperationRegistryCheck::class)->execute(['test.undeclared' => $this->definition]))
        ->toThrow(LogicException::class, 'needs a direct workflow and projection');
});

it('rejects partial old contracts and invalid direct result or admission declarations', function (string $case): void {
    $definition = $this->definition;
    match ($case) {
        'completion handler' => $definition['completion'] = stdClass::class,
        'completion proof' => $definition['completion_contract'] = [],
        'execution relay' => $definition['execution'] = stdClass::class,
        'partial runs' => $definition['runs'] = [],
        'result schema' => $definition['result_fields'] = [['name' => 'items', 'list_of' => [], 'max' => 1]],
        'admission class' => $definition['admission'] = stdClass::class,
    };

    expect(fn () => new OperationKinds(app(), ['test.invalid' => $definition]))->toThrow(LogicException::class);
})->with(['completion handler', 'completion proof', 'execution relay', 'partial runs', 'result schema', 'admission class']);

it('refuses a deleted writer before admitting an Operation', function (string $case): void {
    $direct = app(DirectOperation::class);
    $definition = $direct->definition('route.caddy_converge');
    config([$definition['writer_config'] => ['route.caddy_converge' => 'polled']]);
    $kind = $case === 'missing declaration' ? 'test.undeclared' : 'route.caddy_converge';

    expect(fn () => app(OperationStore::class)->create($kind, 'node', 1, null, [], null, null))
        ->toThrow(ResourceOperationException::class, 'requires its direct Swamp writer');
    $this->assertDatabaseCount('operations', 0);
})->with(['polled setting', 'missing declaration']);

it('refuses a scope mismatch instead of falling back to a deleted writer', function (): void {
    $direct = app(DirectOperation::class);
    $definition = $direct->definition('route.caddy_converge');
    config([$definition['writer_config'] => ['route.caddy_converge' => 'direct']]);
    $definition['writer_scope'] = ['manager' => 'apt'];
    $reflection = new ReflectionClass(DirectOperation::class);
    $changed = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('definitions')->setValue($changed, ['route.caddy_converge' => $definition]);
    app()->instance(DirectOperation::class, $changed);

    expect(fn () => app(OperationStore::class)->create('route.caddy_converge', 'node', 1, null, ['manager' => 'vp'], null, null))
        ->toThrow(ResourceOperationException::class, 'requires its direct Swamp writer');
    $this->assertDatabaseCount('operations', 0);
});

it('admits and projects a direct-only result without a claim or receipt', function (bool $configured): void {
    $direct = app(DirectOperation::class);
    $definition = $direct->definition('route.caddy_converge');
    config([$definition['writer_config'] => $configured ? ['route.caddy_converge' => 'direct'] : []]);
    $operation = app(OperationStore::class)->create('route.caddy_converge', 'node', 1, null, [], null, null);
    $run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $operation->update(['state' => [...$operation->state, 'swamp_run_id' => $run]]);
    $contract = app(OperationExecutionContract::class);
    $result = $contract->result($operation, ['ready' => true, 'undeclared' => 'private']);

    $direct->project($operation, ['run_id' => $run, 'status' => 'succeeded', 'started_at' => '2026-10-30T12:00:00Z',
        'finished_at' => '2026-10-30T12:00:01Z'], $result, 'publish');

    expect($operation->fresh()->status)->toBe(OperationStatus::Succeeded);
    expect($operation->fresh()->result)->toBe(['ready' => true]);
    expect($operation->fresh()->lock_key)->toBeNull();
    expect($operation->fresh()->attempts)->toBe(0);
    expect($operation->fresh()->worker)->toBeNull();
    expect($operation->fresh()->state)->not->toHaveKeys(['executor_runs', 'executor_lease_node_id']);
})->with(['explicit direct setting' => true, 'default direct after deletion' => false]);

it('refuses old claims and execution even for unstamped pre-cutover rows', function (): void {
    $operation = Operation::query()->create(['kind' => 'route.caddy_converge', 'target_type' => 'node', 'target_id' => 1,
        'status' => OperationStatus::Queued, 'sequence' => 1, 'input' => [], 'state' => []]);
    $executor = app(SwampOperationExecutor::class);

    expect(fn () => $executor->claim($operation->kind, 'old-worker', 1, $operation->id))
        ->toThrow(ResourceOperationException::class);
    expect(fn () => app(OperationExecutionContract::class)->context($operation))->toThrow(ResourceOperationException::class, 'no Gateway execution contract');
    expect(fn () => app(OperationExecutionContract::class)->assertCompletion($operation, []))->toThrow(ResourceOperationException::class, 'no Gateway execution contract');
    expect($operation->fresh()->status)->toBe(OperationStatus::Queued);
    expect($operation->fresh()->worker)->toBeNull();
    expect($operation->fresh()->attempts)->toBe(0);
});
