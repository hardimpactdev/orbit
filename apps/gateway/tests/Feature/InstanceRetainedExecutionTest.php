<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceStepListAction;
use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

it('refuses retained list admission inside a caller transaction', function (): void {
    $instance = dev935_instance();
    DB::transaction(function () use ($instance): void {
        expect(fn () => app(StartInstanceStepListAction::class)->retained($instance, LifecyclePhase::Teardown, 'test'))
            ->toThrow(fn (ResourceOperationException $failure) => expect($failure->errorCode)->toBe('operation.await_in_transaction'));
    });
    expect(Operation::query()->count())->toBe(0);
});

it('retains a failed child outcome in the bounded Gateway caller error', function (): void {
    $instance = dev935_instance();
    $lists = app(StartInstanceStepListAction::class);
    $operation = $lists->execute($instance, LifecyclePhase::Teardown, 'test', null);
    $operation->update(['status' => OperationStatus::Failed, 'error_code' => 'instance.lifecycle_busy',
        'result' => ['outcome' => 'busy', 'failed_step' => 'teardown.cleanup']]);

    DB::commit();
    expect(fn () => $lists->await($operation))
        ->toThrow(fn (ResourceOperationException $failure) => expect($failure->errorCode)->toBe('instance.lifecycle_busy')
            ->and($failure->details)->toBe(['operation_id' => $operation->id, 'outcome' => 'busy', 'step' => 'teardown.cleanup']));
    expect($operation->refresh()->status)->toBe(OperationStatus::Failed);
});

it('propagates a caller deadline without cancelling or readmitting the list', function (): void {
    $instance = dev935_instance();
    $lists = app(StartInstanceStepListAction::class);
    $operation = $lists->execute($instance, LifecyclePhase::Teardown, 'test', null);
    DB::commit();
    Sleep::fake();
    try {
        Sleep::whenFakingSleep(function () use ($operation): never {
            throw new ResourceOperationException('operation.await_timeout', 'Caller deadline.', 409, details: ['operation_id' => $operation->id]);
        });
        expect(fn () => $lists->await($operation))
            ->toThrow(fn (ResourceOperationException $failure) => expect($failure->errorCode)->toBe('operation.await_timeout')
                ->and($failure->details)->toBe(['operation_id' => $operation->id, 'outcome' => 'unconfirmed']));
        expect($operation->refresh()->status)->toBe(OperationStatus::Queued)
            ->and($operation->cancel_requested_at)->toBeNull()->and(Operation::query()->count())->toBe(1);
    } finally {
        Sleep::fake(false);
    }
});
