<?php

declare(strict_types=1);

use App\Actions\Operations\CancelOperationAction;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;

it('settles an existing cancellation only when no native run or attempt exists', function (string $status): void {
    $this->freezeSecond();
    $operation = Operation::query()->create(['kind' => 'doctor.inspect', 'target_type' => 'node', 'target_id' => 1,
        'status' => $status, 'sequence' => 4, 'attempts' => 0, 'state' => [], 'input' => [],
        'cancel_requested_at' => now()->subMinute(), 'lock_key' => 'node:1']);
    $requested = $operation->cancel_requested_at;

    app(CancelOperationAction::class)->execute($operation);

    expect($operation->status)->toBe(OperationStatus::Cancelled);
    expect($operation->finished_at->equalTo(now()))->toBeTrue();
    expect($operation->cancel_requested_at->equalTo($requested))->toBeTrue();
    expect($operation->lock_key)->toBeNull();
    expect($operation->error_code)->toBeNull();
    expect($operation->sequence)->toBe(5);
    app(CancelOperationAction::class)->execute($operation);
    expect($operation->sequence)->toBe(5);
})->with(['queued', 'running']);

it('leaves native or previously attempted work cooperative', function (string $status, int $attempts, array $state): void {
    $operation = Operation::query()->create(['kind' => 'doctor.inspect', 'target_type' => 'node', 'target_id' => 1,
        'status' => $status, 'sequence' => 1, 'attempts' => $attempts, 'state' => $state, 'input' => []]);

    app(CancelOperationAction::class)->execute($operation);

    expect($operation->status->value)->toBe($status);
    expect($operation->cancel_requested_at)->not->toBeNull();
    expect($operation->finished_at)->toBeNull();
    expect($operation->state)->toBe($state);
})->with(['attempted' => ['running', 1, []], 'waiting' => ['waiting', 0, []],
    'bound' => ['running', 0, ['swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]]);

it('preserves an existing terminal result on cancellation', function (string $status): void {
    $operation = Operation::query()->create(['kind' => 'doctor.inspect', 'target_type' => 'node', 'target_id' => 1,
        'status' => $status, 'sequence' => 2, 'attempts' => 0, 'state' => [], 'input' => [],
        'finished_at' => now(), 'result' => ['retained' => true]]);
    $operation->refresh();
    $before = $operation->getRawOriginal();

    app(CancelOperationAction::class)->execute($operation);

    expect($operation->getRawOriginal())->toBe($before);
})->with(['succeeded', 'failed', 'cancelled']);
