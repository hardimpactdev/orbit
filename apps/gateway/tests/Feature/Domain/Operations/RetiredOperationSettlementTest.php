<?php

declare(strict_types=1);

use App\Actions\Operations\CancelOperationAction;
use App\Domain\Operations\OperationStatus;
use App\Jobs\Operations\ReconcileSwampOperations;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/** A merge of a recipe PR, the kind that left the registry with fleet maintenance, holding its target since `$minutes` ago. */
function retiredKindOperation(string $kind = 'fleet.recipe_pr', int $minutes = 30, array $state = [], int $attempts = 0, int $target = 2563): Operation
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->firstOrCreate(['name' => 'swamp'], ['status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.15', 'wireguard_ip' => '10.44.0.15', 'user' => 'orbit']));

    return Operation::query()->create(['kind' => $kind, 'target_type' => 'recipe_pr', 'target_id' => $target, 'node_id' => $node->id, 'lock_key' => "recipe_pr:{$target}",
        'input' => ['action' => 'merge'], 'status' => OperationStatus::Running, 'state' => $state, 'sequence' => 1, 'attempts' => $attempts,
        'started_at' => now()->subMinutes($minutes)]);
}

function passOverRetiredKinds(): void
{
    app()->call([new ReconcileSwampOperations, 'handle']);
}

it('cancels an Operation of a retired kind at once and frees its target, even when a run is recorded', function (): void {
    $operation = retiredKindOperation(state: ['swamp_run_id' => 'ea55ab37-bcbe-4459-81dc-cbb397e7d870']);

    $cancelled = app(CancelOperationAction::class)->execute($operation);

    expect($cancelled->status)->toBe(OperationStatus::Cancelled)
        ->and($cancelled->lock_key)->toBeNull()
        ->and($cancelled->cancel_requested_at)->not->toBeNull()
        ->and($cancelled->finished_at)->not->toBeNull();
});

it('settles a retired Operation whose cancel request was recorded before, on the next pass', function (): void {
    $operation = retiredKindOperation();
    $operation->forceFill(['cancel_requested_at' => now()->subHour()])->save();

    passOverRetiredKinds();

    expect($operation->refresh()->status)->toBe(OperationStatus::Cancelled)->and($operation->lock_key)->toBeNull();
});

it('fails a retired Operation at its admission budget and frees its target', function (): void {
    $stale = retiredKindOperation(minutes: 11);
    $young = retiredKindOperation(minutes: 5, target: 9);

    passOverRetiredKinds();

    expect($stale->refresh()->status)->toBe(OperationStatus::Failed)
        ->and($stale->error_code)->toBe('operation.timed_out')
        ->and($stale->lock_key)->toBeNull()
        ->and($young->refresh()->status)->toBe(OperationStatus::Running)
        ->and($young->lock_key)->toBe('recipe_pr:9');
});

it('leaves an Operation of a registered kind to its own path', function (): void {
    $operation = retiredKindOperation(kind: 'proof.run');
    $operation->forceFill(['cancel_requested_at' => now()->subHour()])->save();

    passOverRetiredKinds();

    expect($operation->refresh()->status)->toBe(OperationStatus::Running)->and($operation->lock_key)->toBe('recipe_pr:2563');
});

it('gives a retired Operation with a bound run the history budget, 30 minutes without history', function (): void {
    $running = retiredKindOperation(minutes: 20, state: ['swamp_run_id' => 'ea55ab37-bcbe-4459-81dc-cbb397e7d870'], attempts: 1);
    $over = retiredKindOperation(minutes: 31, state: ['swamp_run_id' => 'ea55ab37-bcbe-4459-81dc-cbb397e7d871'], attempts: 1, target: 7);

    passOverRetiredKinds();

    expect($running->refresh()->status)->toBe(OperationStatus::Running)
        ->and($over->refresh()->status)->toBe(OperationStatus::Failed)
        ->and($over->lock_key)->toBeNull();
});

it('leaves a finished Operation of a retired kind as it is', function (): void {
    $done = retiredKindOperation(minutes: 90);
    $done->forceFill(['status' => OperationStatus::Succeeded, 'finished_at' => now()->subHour(), 'lock_key' => null])->save();

    passOverRetiredKinds();

    expect($done->refresh()->status)->toBe(OperationStatus::Succeeded)->and($done->error_code)->toBeNull();
});
