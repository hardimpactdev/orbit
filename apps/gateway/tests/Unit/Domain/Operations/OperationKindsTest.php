<?php

declare(strict_types=1);

use App\Domain\Instances\Deployment\InstanceDeployOperation;
use App\Domain\Operations\OperationKinds;
use App\Domain\Tasks\TaskPublishOperation;
use Illuminate\Container\Container;

describe(OperationKinds::class, function (): void {
    it('names every kind and target type in the form that the list filters accept', function (): void {
        expect(OperationKinds::names())->not->toBeEmpty()->each->toMatch(OperationKinds::NamePattern)
            ->and(OperationKinds::InstanceTarget)->toMatch(OperationKinds::TargetTypePattern);
    });
    it('discovers operation declarations from separate domains', function (): void {
        expect(OperationKinds::definitions())
            ->toHaveKey(OperationKinds::InstanceDeploy, InstanceDeployOperation::class)
            ->toHaveKey(OperationKinds::TasksPublish, TaskPublishOperation::class);
        expect(OperationKinds::executor(OperationKinds::InstanceDeploy))->toBe('php');
    });

    it('refuses a Swamp declaration on the PHP execution path', function (): void {
        $kinds = new OperationKinds(new Container, [
            'test.remote' => OperationKinds::definitions()['firewall.allow'],
        ]);

        expect(fn () => $kinds->for('test.remote'))->toThrow(LogicException::class, 'not executed by PHP');
    });
});

it('refuses unenforced kind run and completion guarantees while loading declarations', function (string $part, string $key): void {
    $definition = OperationKinds::definitions()['firewall.allow'];
    if ($part === 'kind') {
        $definition[$key] = ['route.caddy_converge'];
    } elseif ($part === 'runs') {
        $definition['runs'][0][$key] = true;
    } else {
        $definition['completion_contract'][$key] = ['route.caddy_converge'];
    }
    expect(fn () => new OperationKinds(new Container, ['test.remote' => $definition]))
        ->toThrow(LogicException::class, "unsupported {$part} keys: {$key}");
})->with([
    ['kind', 'children_succeeded'], ['kind', 'unknown_guarantee'],
    ['completion_contract', 'children'],
    ['runs', 'unknown_guard'],
]);

it('reports optional capabilities unavailable until their enforcement ships', function (string $capability): void {
    expect(OperationKinds::supports($capability))->toBeFalse();
})->with(['secret_outputs', 'unknown']);

it('reports enforced child and target receipt capabilities', function (): void {
    expect(OperationKinds::supports('children'))->toBeTrue()
        ->and(OperationKinds::supports('receipts'))->toBeTrue()
        ->and(OperationKinds::supports('compensations'))->toBeTrue()
        ->and(OperationKinds::supports('list_of'))->toBeTrue()
        ->and(OperationKinds::supports('children_settled'))->toBeTrue();
});

it('rejects a child-only completion contract that proves no child', function (): void {
    $definition = OperationKinds::definitions()['firewall.allow'];
    $definition['completion_contract'] = ['children_succeeded' => []];
    expect(fn () => new OperationKinds(new Container, ['test.empty' => $definition]))
        ->toThrow(LogicException::class, 'complete enforced completion proof');
});


it('refuses child Node relations from Swamp-written state', function (string $path): void {
    $definition = OperationKinds::definitions()['firewall.allow'];
    $definition['children'] = [['kind' => 'test.child', 'node_from' => $path]];
    expect(fn () => new OperationKinds(new Container, ['test.parent' => $definition]))->toThrow(LogicException::class);
})->with(['state.steps.inspection.node_id', 'state.executor_runs.inspect.result.node_id', 'state.executor_reentry.children.0.node_id', 'state.unknown_plan.node_id']);

it('requires compensation kinds to be declared with a bounded child boundary key', function (string $case): void {
    $definition = OperationKinds::definitions()['firewall.allow'];
    $definition['children'] = [['kind' => 'test.child', 'node_from' => 'operation.node_id']];
    $definition['compensations'] = ['test.child'];
    $definition['cancellation_boundary'] = 'stop-source';
    match ($case) {
        'kind' => $definition['compensations'] = ['test.foreign'],
        'empty' => $definition['compensations'] = [],
        'boundary' => $definition['cancellation_boundary'] = '',
    };
    expect(fn () => new OperationKinds(new Container, ['test.parent' => $definition]))->toThrow(LogicException::class);
})->with(['kind', 'empty', 'boundary']);

it('rejects result declarations that cannot be enforced', function (string $case): void {
    $field = ['name' => 'artifacts', 'list_of' => ['node_id' => 'int'], 'max' => 4];
    match ($case) {
        'zero bound' => $field['max'] = 0,
        'large bound' => $field['max'] = 4097,
        'unknown field' => $field['unimplemented'] = true,
        'unsupported type' => $field['list_of']['node_id'] = 'object',
        'empty object' => $field['list_of'] = [],
        'nested object' => $field['list_of']['node_id'] = ['list_of' => ['id' => 'int'], 'max' => 4],
        'unbounded nested' => $field['list_of']['node_id'] = ['type' => 'list', 'items' => 'integer'],
    };
    $definition = OperationKinds::definitions()['firewall.allow'];
    $definition['result_fields'] = [$field];
    expect(fn () => new OperationKinds(new Container, ['test.invalid' => $definition]))->toThrow(LogicException::class);
})->with(['zero bound', 'large bound', 'unknown field', 'unsupported type', 'empty object', 'nested object', 'unbounded nested']);

it('rejects malformed completion variants at kind load', function (string $case): void {
    $definition = OperationKinds::definitions()['firewall.allow'];
    $variant = ['by' => 'manager', 'map' => ['apt' => $definition['completion_contract']]];
    match ($case) {
        'missing selector' => $variant['by'] = '',
        'empty map' => $variant['map'] = [],
        'incomplete proof' => $variant['map']['apt'] = ['step' => 'verified'],
        'unknown guarantee' => $variant['map']['apt']['unknown'] = true,
    };
    $definition['completion_contract'] = ['completion' => $variant];
    expect(fn () => new OperationKinds(new Container, ['test.invalid' => $definition]))->toThrow(LogicException::class);
})->with(['missing selector', 'empty map', 'incomplete proof', 'unknown guarantee']);
