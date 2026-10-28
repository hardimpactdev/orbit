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
            'test.remote' => ['executor' => 'swamp', 'completion' => stdClass::class],
        ]);

        expect(fn () => $kinds->for('test.remote'))->toThrow(LogicException::class, 'not executed by PHP');
    });
});
