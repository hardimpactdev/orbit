<?php

declare(strict_types=1);

use App\Domain\Operations\AwaitOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use Illuminate\Support\Sleep;

function awaitKitOperation(OperationStatus $status = OperationStatus::Queued): Operation
{
    return Operation::query()->create(['kind' => 'test.await', 'target_type' => 'node', 'target_id' => 27,
        'node_id' => null, 'status' => $status, 'input' => [], 'sequence' => 1, 'attempts' => 0]);
}

it('returns every terminal outcome without sleeping or changing it', function (OperationStatus $status): void {
    Sleep::fake();
    try {
        $operation = awaitKitOperation($status);
        $operation->update(['result' => ['outcome' => $status->value], 'error_code' => $status === OperationStatus::Failed ? 'test.failed' : null]);
        $result = app(AwaitOperation::class)->execute($operation);
        expect($result->id)->toBe($operation->id)->and($result->status)->toBe($status)
            ->and($result->result)->toBe(['outcome' => $status->value]);
        Sleep::assertNeverSlept();
    } finally {
        Sleep::fake(false);
    }
})->with([OperationStatus::Succeeded, OperationStatus::Failed, OperationStatus::Cancelled]);

it('sleeps between reads until the same Operation reaches a terminal receipt', function (): void {
    Sleep::fake();
    try {
        $operation = awaitKitOperation();
        Sleep::whenFakingSleep(function () use ($operation): void {
            Operation::query()->whereKey($operation->id)->update(['status' => OperationStatus::Succeeded, 'result' => ['outcome' => 'succeeded']]);
        });
        $result = app(AwaitOperation::class)->execute($operation);
        expect($result->status)->toBe(OperationStatus::Succeeded)->and($result->attempts)->toBe(0);
        Sleep::assertSleptTimes(1);
    } finally {
        Sleep::fake(false);
    }
});

it('ends the caller wait on deadline without cancelling or changing the pending Operation', function (): void {
    $operation = awaitKitOperation(OperationStatus::Waiting);
    $started = hrtime(true);
    try {
        app(AwaitOperation::class)->execute($operation, 1);
        test()->fail('A pending Operation must reach the caller deadline.');
    } catch (ResourceOperationException $error) {
        expect($error->errorCode)->toBe('operation.await_timeout')->and($error->details)->toBe(['operation_id' => $operation->id]);
    }
    expect((hrtime(true) - $started) / 1_000_000_000)->toBeGreaterThanOrEqual(1)->toBeLessThan(3)
        ->and($operation->refresh()->status)->toBe(OperationStatus::Waiting)
        ->and($operation->cancel_requested_at)->toBeNull()->and($operation->attempts)->toBe(0);
});

it('refuses an unbounded or zero caller wait', function (int $seconds): void {
    expect(fn () => app(AwaitOperation::class)->execute(awaitKitOperation(), $seconds))->toThrow(InvalidArgumentException::class);
})->with([0, -1, 121]);
