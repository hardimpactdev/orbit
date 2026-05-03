<?php

declare(strict_types=1);

use Tests\E2E\Support\E2EConfig;

/**
 * @param  array<string, string>  $values
 */
function withE2EConfigEnvironment(array $values, Closure $callback): void
{
    $keys = [
        'ORBIT_E2E_CPUS',
        'ORBIT_E2E_MEMORY',
        'ORBIT_E2E_TOPOLOGY_CPUS',
        'ORBIT_E2E_TOPOLOGY_MEMORY',
    ];
    $previous = [];

    foreach ($keys as $key) {
        $previous[$key] = getenv($key);
        putenv($key);
    }

    foreach ($values as $key => $value) {
        putenv("{$key}={$value}");
    }

    try {
        $callback();
    } finally {
        foreach ($previous as $key => $value) {
            if (is_string($value)) {
                putenv("{$key}={$value}");

                continue;
            }

            putenv($key);
        }
    }
}

it('defaults topology cpus to 1 and topology memory to 2GiB', function (): void {
    withE2EConfigEnvironment([], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect($config->topologyCpus)->toBe('1')
            ->and($config->topologyMemory)->toBe('2GiB');
    });
});

it('keeps provisioning cpu/memory defaults at 2 / 2GiB', function (): void {
    withE2EConfigEnvironment([], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect($config->cpus)->toBe('2')
            ->and($config->memory)->toBe('2GiB');
    });
});

it('overrides topology limits independently from provisioning limits', function (): void {
    withE2EConfigEnvironment([
        'ORBIT_E2E_CPUS' => '4',
        'ORBIT_E2E_MEMORY' => '8GiB',
        'ORBIT_E2E_TOPOLOGY_CPUS' => '2',
        'ORBIT_E2E_TOPOLOGY_MEMORY' => '3GiB',
    ], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect($config->cpus)->toBe('4')
            ->and($config->memory)->toBe('8GiB')
            ->and($config->topologyCpus)->toBe('2')
            ->and($config->topologyMemory)->toBe('3GiB');
    });
});

it('preserves topology limits across forHost', function (): void {
    withE2EConfigEnvironment([
        'ORBIT_E2E_TOPOLOGY_CPUS' => '1',
        'ORBIT_E2E_TOPOLOGY_MEMORY' => '2GiB',
    ], function (): void {
        $config = E2EConfig::fromEnvironment()->forHost('sidecar1');

        expect($config->host)->toBe('sidecar1')
            ->and($config->topologyCpus)->toBe('1')
            ->and($config->topologyMemory)->toBe('2GiB');
    });
});
