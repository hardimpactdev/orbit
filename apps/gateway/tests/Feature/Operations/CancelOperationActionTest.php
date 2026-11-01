<?php

declare(strict_types=1);

use App\Actions\Operations\CancelOperationAction;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use Illuminate\Support\Facades\Http;

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

it('sends a bound Task turn or check to native cancellation without inventing a terminal result', function (string $kind): void {
    config(['swamp.run_token' => 'fixture.fake', 'swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/public/ca.pem']);
    Http::fake(['https://swamp.orbit/api/v1/cancel/workflow-run/*' => Http::response([], 200)]);
    $run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $operation = Operation::query()->create(['kind' => $kind, 'target_type' => 'task_group', 'target_id' => 17,
        'status' => 'running', 'sequence' => 1, 'attempts' => 1, 'state' => ['swamp_run_id' => $run], 'input' => []]);

    app(CancelOperationAction::class)->execute($operation);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://swamp.orbit/api/v1/cancel/workflow-run/'.$run);
    expect($operation->refresh()->status)->toBe(OperationStatus::Running)
        ->and($operation->cancel_requested_at)->not->toBeNull()
        ->and($operation->finished_at)->toBeNull();
})->with(['tasks.turn', 'tasks.check']);
