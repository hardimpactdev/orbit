<?php

declare(strict_types=1);

use App\Actions\TaskVms\DeleteTaskVmAction;
use App\Actions\TaskVms\StartTaskVmIncusEffectAction;
use App\Actions\TaskVms\StartTaskVmLifecycleOperationAction;
use App\Domain\Shared\ResourceOperationException;
use App\Models\TaskVm;
use Illuminate\Support\Facades\Queue;

it('refuses retired lifecycle admissions before recording intent or queuing execution', function (string $verb): void {
    Queue::fake();
    expect(fn () => app(StartTaskVmLifecycleOperationAction::class)->execute(999, $verb))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.kind_unavailable')->and($error->status)->toBe(409));
    $this->assertDatabaseCount('operations', 0);
    Queue::assertNothingPushed();
})->with(['provision', 'enroll', 'resume', 'destroy']);

it('refuses retired standalone Incus effect admission without inspecting a host', function (string $verb): void {
    Queue::fake();
    expect(fn () => app(StartTaskVmIncusEffectAction::class)->execute(new TaskVm, $verb))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.kind_unavailable')->and($error->status)->toBe(409));
    $this->assertDatabaseCount('operations', 0);
    Queue::assertNothingPushed();
})->with(['create', 'start', 'stop', 'delete']);

it('refuses synchronous deletion before changing a task VM or calling a retired runtime method', function (): void {
    $vm = new TaskVm(['state' => 'ready', 'name' => 'tvm-refused']);
    expect(fn () => app(DeleteTaskVmAction::class)->execute($vm))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.kind_unavailable')->and($error->status)->toBe(409));
    expect($vm->state->value)->toBe('ready');
    $this->assertDatabaseCount('operations', 0);
});
