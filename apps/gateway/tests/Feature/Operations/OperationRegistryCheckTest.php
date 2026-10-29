<?php

declare(strict_types=1);

use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationRegistryCheck;
use App\Domain\Shared\ResourceOperationException;

it('boots and resolves every registered family without reading resource records', function (): void {
    expect(app(OperationRegistryCheck::class)->execute())->toBe(count(OperationKinds::definitions()))->toBeGreaterThan(0);
});

it('fails on an unsupported family or completion guarantee', function (string $part): void {
    $definition = OperationKinds::definitions()['firewall.allow'];
    if ($part === 'kind') {
        $definition['unknown_guarantee'] = true;
    } else {
        $definition['completion_contract']['unknown_guarantee'] = true;
    }
    expect(fn () => app(OperationRegistryCheck::class)->execute(['test.broken' => $definition]))
        ->toThrow(LogicException::class, 'unsupported');
})->with(['kind', 'completion']);

it('fails when a declared handler or named transport cannot resolve', function (string $part): void {
    $definition = OperationKinds::definitions()['firewall.allow'];
    if ($part === 'transport') {
        $definition['runs'][0]['transport'] = 'unregistered';
    } else {
        $definition[$part] = stdClass::class;
    }
    expect(fn () => app(OperationRegistryCheck::class)->execute(['test.broken' => $definition]))
        ->toThrow($part === 'transport' ? ResourceOperationException::class : LogicException::class);
})->with(['completion', 'execution', 'transport']);

it('fails when a PHP executor does not implement its contract', function (): void {
    expect(fn () => app(OperationRegistryCheck::class)->execute(['test.broken' => stdClass::class]))->toThrow(TypeError::class);
});

it('fails when a Gateway command name has no registered handler', function (): void {
    $definition = OperationKinds::definitions()['firewall.allow'];
    $definition['runs'][0]['transport'] = 'gateway';
    $definition['runs'][0]['name'] = 'test.missing-command';
    expect(fn () => app(OperationRegistryCheck::class)->execute(['test.broken' => $definition]))
        ->toThrow(ResourceOperationException::class);
});
