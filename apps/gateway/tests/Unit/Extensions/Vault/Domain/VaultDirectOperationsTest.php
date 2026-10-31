<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationContribution;
use Orbit\Extensions\Vault\Domain\VaultDirectOperations;
use Tests\TestCase;

uses(TestCase::class);

it('wires the Vault provider into the production direct-start registry', function (): void {
    $contribution = collect(app()->tagged(DirectOperationContribution::class))->first(fn ($item): bool => $item instanceof VaultDirectOperations);
    expect($contribution)->toBeInstanceOf(VaultDirectOperations::class);
    foreach ($contribution->definitions() as $kind => $definition) {
        expect(app(DirectOperation::class)->definition($kind))->toBe($definition);
    }
});
