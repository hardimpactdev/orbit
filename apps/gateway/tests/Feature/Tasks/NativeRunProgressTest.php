<?php

declare(strict_types=1);

use App\Domain\Operations\OperationStatus;
use App\Infrastructure\Swamp\NativeRunProgress;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/** A running Operation that follows the Swamp run `run-1`. */
function progressOperation(): Operation
{
    return Operation::query()->create(['kind' => 'tasks.provision', 'target_type' => 'task_group', 'target_id' => 1, 'node_id' => null,
        'status' => OperationStatus::Running, 'sequence' => 1, 'attempts' => 1, 'input' => [], 'state' => ['swamp_run_id' => 'run-1']]);
}

/** @return list<array<string,mixed>> the progress rows of the Operation, in event order */
function progressRows(Operation $operation): array
{
    return array_values($operation->refresh()->state['native_progress']['steps']);
}

it('keeps all four clone phases visible while publication runs in its own job', function (): void {
    $operation = progressOperation();
    $operation->update(['kind' => 'instance.create']);
    $progress = app(NativeRunProgress::class);
    $sequence = 0;
    $progress->apply($operation->id, 'run-1', ['kind' => 'step_started', 'jobId' => 'publication', 'stepId' => 'clone-route',
        'startedAt' => '2026-11-10T20:00:00.000Z', 'seq' => ++$sequence]);
    foreach (['container', 'workspace', 't3', 'sessions'] as $index => $phase) {
        $started = sprintf('2026-11-10T20:00:%02d.000Z', $index + 1);
        $finished = sprintf('2026-11-10T20:00:%02d.500Z', $index + 1);
        $progress->apply($operation->id, 'run-1', ['kind' => 'step_started', 'jobId' => 'clone', 'stepId' => 'clone-'.$phase,
            'startedAt' => $started, 'seq' => ++$sequence]);
        expect($operation->refresh()->current_step)->toBe('clone-'.$phase);
        $progress->apply($operation->id, 'run-1', ['kind' => 'step_completed', 'jobId' => 'clone', 'stepId' => 'clone-'.$phase,
            'completedAt' => $finished, 'seq' => ++$sequence]);
    }

    $rows = progressRows($operation);
    expect(array_column($rows, 'name'))->toBe(['clone-route', 'clone-container', 'clone-workspace', 'clone-t3', 'clone-sessions'])
        ->and(array_column(array_slice($rows, 1), 'status'))->toBe(array_fill(0, 4, 'succeeded'))
        ->and(array_column(array_slice($rows, 1), 'started_at'))->not->toContain(null)
        ->and(array_column(array_slice($rows, 1), 'finished_at'))->not->toContain(null)
        ->and($operation->current_step)->toBe('clone-route');
});

it('ends a forEach item whose end event carries no index on the row its start opened', function (): void {
    $operation = progressOperation();
    $progress = app(NativeRunProgress::class);
    // Swamp sends the item index with the start of a forEach item, and not with its end (op 12083, Route create).
    $events = [
        ['kind' => 'step_started', 'jobId' => 'create', 'stepId' => 'Sign the CSR-0', 'forEachIndex' => 0],
        ['kind' => 'step_started', 'jobId' => 'create', 'stepId' => 'Sign the CSR-1', 'forEachIndex' => 1],
        ['kind' => 'step_completed', 'jobId' => 'create', 'stepId' => 'Sign the CSR-0'],
        ['kind' => 'step_completed', 'jobId' => 'create', 'stepId' => 'Sign the CSR-1'],
    ];
    foreach ($events as $seq => $event) {
        $progress->apply($operation->id, 'run-1', [...$event, 'seq' => $seq + 1, 'parentRunId' => 'run-1']);
    }

    expect(array_map(static fn (array $row): array => [$row['name'], $row['status']], progressRows($operation)))
        ->toBe([['Sign the CSR-0-0', 'succeeded'], ['Sign the CSR-1-1', 'succeeded']])
        ->and($operation->current_step)->toBeNull();
});

it('keeps the index of an end event that carries one', function (): void {
    $operation = progressOperation();
    $progress = app(NativeRunProgress::class);
    $progress->apply($operation->id, 'run-1', ['kind' => 'step_started', 'jobId' => 'source', 'stepId' => 'setup-0', 'forEachIndex' => 0, 'seq' => 1]);
    $progress->apply($operation->id, 'run-1', ['kind' => 'step_completed', 'jobId' => 'source', 'stepId' => 'setup-0', 'forEachIndex' => 0, 'seq' => 2]);

    expect(array_map(static fn (array $row): array => [$row['name'], $row['status']], progressRows($operation)))->toBe([['setup-0-0', 'succeeded']]);
});

it('leaves no step running once the Operation ends, on every path that saves its status', function (OperationStatus $status, string $ended): void {
    $operation = progressOperation();
    app(NativeRunProgress::class)->apply($operation->id, 'run-1', ['kind' => 'step_started', 'jobId' => 'create', 'stepId' => 'Never reported', 'seq' => 1]);
    app(NativeRunProgress::class)->apply($operation->id, 'run-1', ['kind' => 'step_started', 'jobId' => 'create', 'stepId' => 'Done', 'seq' => 2]);
    app(NativeRunProgress::class)->apply($operation->id, 'run-1', ['kind' => 'step_completed', 'jobId' => 'create', 'stepId' => 'Done', 'seq' => 3]);

    $operation->refresh()->update(['status' => $status]);

    expect(array_map(static fn (array $row): array => [$row['name'], $row['status']], progressRows($operation)))
        ->toBe([['Never reported', $ended], ['Done', 'succeeded']])
        ->and($operation->state['native_progress']['active'])->toBe([]);
})->with([
    'succeeded' => [OperationStatus::Succeeded, 'succeeded'],
    'cancelled' => [OperationStatus::Cancelled, 'cancelled'],
    'failed' => [OperationStatus::Failed, 'interrupted'],
]);

it('closes no row with an index-less skip of a started forEach item', function (): void {
    $operation = progressOperation();
    $progress = app(NativeRunProgress::class);
    $progress->apply($operation->id, 'run-1', ['kind' => 'step_started', 'jobId' => 'create', 'stepId' => 'Sign the CSR-0', 'forEachIndex' => 0, 'seq' => 1]);
    $progress->apply($operation->id, 'run-1', ['kind' => 'step_skipped', 'jobId' => 'create', 'stepId' => 'Sign the CSR-0', 'seq' => 2]);

    expect(array_map(static fn (array $row): array => [$row['name'], $row['status']], progressRows($operation)))
        ->toBe([['Sign the CSR-0-0', 'running'], ['Sign the CSR-0', 'skipped']]);
});

it('keeps a failed publication step of a clone create out of its failed step, so a later clone failure names itself', function (): void {
    $operation = progressOperation();
    $operation->update(['kind' => 'instance.create', 'input' => ['route_id' => 3, 'route_create' => ['route_id' => 3],
        'direct_provisioning' => ['container' => ['clone' => ['source' => 'instance-1']]]]]);
    $progress = app(NativeRunProgress::class);
    // A child of clone-route fails, then clone-route itself; the create goes on (docs/reference/development-containers.md#the-address-during-the-create).
    $progress->apply($operation->id, 'run-1', ['kind' => 'step_failed', 'jobId' => 'create', 'stepId' => 'site', 'parentRunId' => 'run-1', 'seq' => 1]);
    $progress->apply($operation->id, 'run-1', ['kind' => 'step_failed', 'jobId' => 'publication', 'stepId' => 'clone-route', 'seq' => 2]);
    expect($operation->refresh()->failed_step)->toBeNull()
        ->and($operation->state)->not->toHaveKey('native_failure');

    $progress->apply($operation->id, 'run-1', ['kind' => 'step_failed', 'jobId' => 'clone', 'stepId' => 'clone-container', 'seq' => 3]);
    expect($operation->refresh()->failed_step)->toBe('clone-container');
});
