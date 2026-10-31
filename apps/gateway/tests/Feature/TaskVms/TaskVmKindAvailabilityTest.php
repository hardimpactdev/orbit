<?php

declare(strict_types=1);

use App\Actions\TaskVms\StartTaskVmLifecycleOperationAction;
use App\Actions\TaskVms\StartTaskVmRuntimeOperationAction;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use Illuminate\Support\Facades\Queue;

it('refuses unavailable task VM primitives without recording intent or queuing PHP execution', function (string $action, string $capability): void {
    if (OperationKinds::supports($capability) && OperationKinds::supports('receipts')) {
        $this->markTestSkipped('The kit capability is now available.');
    }
    Queue::fake();
    expect(fn () => app($action)->execute(999))->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.kind_unavailable'));
    $this->assertDatabaseCount('operations', 0);
    Queue::assertNothingPushed();
})->with([
    'runtime' => [StartTaskVmRuntimeOperationAction::class, 'children'],
]);

it('never registers a weaker VM contract while child or receipt enforcement is absent', function (): void {
    $kinds = OperationKinds::definitions();
    if (! OperationKinds::supports('children') || ! OperationKinds::supports('receipts')) {
        foreach (['task_vm.prepare_runtime', 'task_vm.provision', 'task_vm.upcloud_image_build'] as $kind) {
            expect($kinds)->not->toHaveKey($kind);
        }
    }

});

it('keeps dependent lifecycle and runtime admissions inert until the owning composition is registered', function (string $verb): void {
    Queue::fake();
    $definitions = OperationKinds::definitions();
    unset($definitions['node.add'], $definitions['node.remove']);
    app()->instance(OperationKinds::class, new OperationKinds(app(), $definitions));
    if ($verb === 'runtime') {
        $admit = fn () => app(StartTaskVmRuntimeOperationAction::class)->execute(999);
    } else {
        $admit = fn () => app(StartTaskVmLifecycleOperationAction::class)->execute(999, $verb);
    }

    expect($admit)->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.kind_unavailable'));
    $this->assertDatabaseCount('operations', 0);
    Queue::assertNothingPushed();
})->with(['provision', 'enroll', 'destroy', 'runtime']);

it('keeps runtime guarded while the owning Node enrollment kind is absent', function (): void {
    $definitions = OperationKinds::definitions();
    unset($definitions['node.add']);
    app()->instance(OperationKinds::class, new OperationKinds(app(), $definitions));
    expect(app(OperationKinds::class)->definition('process.create'))->toBeArray();
    expect(fn () => app(StartTaskVmRuntimeOperationAction::class)->execute(999))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.kind_unavailable'));
    $this->assertDatabaseCount('operations', 0);
});
