<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceLifecycleAvailability;
use App\Domain\Instances\InstanceLifecycleExecution;
use App\Domain\Operations\OperationChildAdmission;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

final readonly class AvailabilityChildAdmission implements OperationChildAdmission
{
    public function admit(Operation $parent, array $intent): Operation
    {
        throw new LogicException('Readiness must not admit a child.');
    }
}

it('refuses unavailable child admissions before a lifecycle can record intent', function (string $childKind, bool $registered): void {
    $child = ['executor' => 'swamp', 'runs' => [['transport' => 'gateway', 'name' => 'test.availability']],
        'completion_contract' => ['step' => 'ready', 'transport' => 'gateway', 'name' => 'test.availability', 'result' => []],
        'result_fields' => []];
    $parent = [...$child, 'execution' => InstanceLifecycleExecution::class,
        'children' => [['kind' => $childKind, 'node_from' => 'operation.node_id']]];
    $kinds = new OperationKinds(app(), ['instance.destroy' => $parent, ...($registered ? [$childKind => $child] : [])]);

    expect(fn () => (new InstanceLifecycleAvailability($kinds))->assertAvailable('instance.destroy'))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('operation.kind_unavailable');
        });

    $this->assertDatabaseCount('operations', 0);
})->with(['instance.run_steps', 'route.withdraw', 'route.withdraw_instance', 'route.cleanup_instance', 'process.destroy', 'schedule.destroy'])
    ->with(['unregistered' => [false], 'no adapter' => [true]]);

it('accepts the complete child registry without admitting or executing a child', function (): void {
    $child = ['executor' => 'swamp', 'admission' => AvailabilityChildAdmission::class,
        'runs' => [['transport' => 'gateway', 'name' => 'test.availability']],
        'completion_contract' => ['step' => 'ready', 'transport' => 'gateway', 'name' => 'test.availability', 'result' => []],
        'result_fields' => []];
    $parent = [...$child, 'execution' => InstanceLifecycleExecution::class,
        'children' => [['kind' => 'instance.run_steps', 'node_from' => 'operation.node_id']]];
    $availability = new InstanceLifecycleAvailability(new OperationKinds(app(), ['instance.destroy' => $parent, 'instance.run_steps' => $child]));

    $availability->assertAvailable('instance.destroy');

    $this->assertDatabaseCount('operations', 0);
});
