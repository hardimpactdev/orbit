<?php

declare(strict_types=1);

use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationRegistryCheck;
use App\Domain\Shared\ResourceOperationException;
use Symfony\Component\Process\Process;

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

it('boots the production registry with an empty in-memory database', function (): void {
    $process = new Process([PHP_BINARY, base_path('../../bin/check-operation-kinds')], base_path(), [
        'APP_CONFIG_CACHE' => sys_get_temp_dir().'/orbit-registry-no-config-cache-'.getmypid().'.php',
        'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => ':memory:',
    ]);

    $process->run();

    expect($process->getExitCode())->toBe(0);
    expect($process->getOutput())->toContain('Operation registry valid:');
});

it('exits nonzero when a production registry dependency cannot be constructed', function (): void {
    $process = new Process([PHP_BINARY, base_path('../../bin/check-operation-kinds')], base_path(), [
        'APP_CONFIG_CACHE' => sys_get_temp_dir().'/orbit-registry-no-config-cache-'.getmypid().'.php',
        'APP_KEY' => 'invalid-fixture-key',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => ':memory:',
    ]);

    $process->run();

    expect($process->getExitCode())->toBe(1);
    expect($process->getErrorOutput())->toContain('Unsupported cipher or incorrect key length');
    expect($process->getOutput())->not->toContain('Operation registry valid:');
});
