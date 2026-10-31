<?php

declare(strict_types=1);

use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationRegistryCheck;
use Symfony\Component\Process\Process;

it('boots and resolves every registered family without reading resource records', function (): void {
    expect(app(OperationRegistryCheck::class)->execute())->toBe(count(OperationKinds::definitions()))->toBeGreaterThan(0);
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

it('checks public declarations without constructing runtime encryption dependencies', function (): void {
    $process = new Process([PHP_BINARY, base_path('../../bin/check-operation-kinds')], base_path(), [
        'APP_CONFIG_CACHE' => sys_get_temp_dir().'/orbit-registry-no-config-cache-'.getmypid().'.php',
        'APP_KEY' => 'invalid-fixture-key',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => ':memory:',
    ]);

    $process->run();

    expect($process->getExitCode())->toBe(0);
    expect($process->getOutput())->toContain('Operation registry valid:');
});

it('refuses a native declaration without a valid result projection', function (array $definition): void {
    expect(fn () => app(OperationRegistryCheck::class)->execute(['test.broken' => $definition]))
        ->toThrow(LogicException::class, 'needs a native result projection');
})->with([
    'missing' => [[]],
    'unrelated class' => [['projection' => stdClass::class]],
    'unknown class' => [['projection' => 'App\\MissingNativeProjection']],
]);
