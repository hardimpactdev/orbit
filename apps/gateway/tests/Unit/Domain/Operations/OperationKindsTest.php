<?php

declare(strict_types=1);

use App\Domain\Operations\OperationKinds;

describe(OperationKinds::class, function (): void {
    it('names every kind and target type in the form that the list filters accept', function (): void {
        expect(OperationKinds::names())->not->toBeEmpty()->each->toMatch(OperationKinds::NamePattern)
            ->and(OperationKinds::InstanceTarget)->toMatch(OperationKinds::TargetTypePattern);
    });
});
