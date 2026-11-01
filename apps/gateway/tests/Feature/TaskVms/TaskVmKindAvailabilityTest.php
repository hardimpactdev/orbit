<?php

declare(strict_types=1);

use App\Actions\TaskVms\DeleteTaskVmAction;
use App\Domain\Shared\ResourceOperationException;
use App\Models\TaskVm;

it('refuses synchronous deletion before changing a task VM or calling a retired runtime method', function (): void {
    $vm = new TaskVm(['state' => 'ready', 'name' => 'tvm-refused']);
    expect(fn () => app(DeleteTaskVmAction::class)->execute($vm))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.kind_unavailable')->and($error->status)->toBe(409));
    expect($vm->state->value)->toBe('ready');
    $this->assertDatabaseCount('operations', 0);
});
