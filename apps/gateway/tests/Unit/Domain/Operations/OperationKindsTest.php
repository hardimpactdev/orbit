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
    ['completion_contract', 'children'], ['completion_contract', 'children_settled'],
    ['runs', 'unknown_guard'],
]);

it('reports optional capabilities unavailable until their enforcement ships', function (string $capability): void {
    expect(OperationKinds::supports($capability))->toBeFalse();
})->with(['children_settled', 'list_of', 'unknown']);

it('reports enforced child and target receipt capabilities', function (): void {
    expect(OperationKinds::supports('children'))->toBeTrue()
        ->and(OperationKinds::supports('receipts'))->toBeTrue();
});
