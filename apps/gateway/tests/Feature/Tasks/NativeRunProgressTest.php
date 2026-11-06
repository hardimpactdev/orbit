<?php

declare(strict_types=1);

use App\Domain\Operations\OperationStatus;
use App\Infrastructure\Swamp\NativeRunProgress;
use App\Infrastructure\Swamp\NativeRunStepTimes;
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

it('takes the history status of a step that never reported its end', function (): void {
    $steps = ['a' => ['job' => 'create', 'name' => 'Sign the CSR-0', 'status' => 'running', 'started_at' => null, 'finished_at' => null]];

    $merged = (new NativeRunStepTimes)->merge($steps, [
        ['job' => 'create', 'name' => 'Sign the CSR-0', 'started_at' => '2026-11-06T17:27:38.064Z', 'finished_at' => '2026-11-06T17:27:39.464Z', 'status' => 'succeeded'],
    ]);

    expect([$merged['a']['status'], $merged['a']['finished_at']])->toBe(['succeeded', '2026-11-06T17:27:39.464Z']);
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
