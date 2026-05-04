<?php

declare(strict_types=1);

use Tests\E2E\Support\E2EConfig;

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

it('defaults topology providers to incus independently from provisioning providers', function (): void {
    withE2EConfigEnvironment([], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect($config->topologyProviderNames)->toBe(['incus'])
            ->and($config->providerNames)->toBe(['incus'])
            ->and($config->forHost('sidecar1')->topologyProviderNames)->toBe(['incus']);
    });
});

it('expands topology provider auto to the safe vm-backed default', function (): void {
    withE2EConfigEnvironment([
        'ORBIT_E2E_TOPOLOGY_PROVIDER' => 'auto',
    ], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect($config->topologyProviderNames)->toBe(['incus']);
    });
});

it('uses explicit topology providers without changing provisioning providers', function (): void {
    withE2EConfigEnvironment([
        'ORBIT_E2E_PROVIDER' => 'hcloud',
        'ORBIT_E2E_TOPOLOGY_PROVIDERS' => 'docker, incus',
    ], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect($config->providerNames)->toBe(['hcloud'])
            ->and($config->topologyProviderNames)->toBe(['docker', 'incus']);
    });
});

it('parses docker topology hosts independently from incus hosts', function (): void {
    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_HOSTS' => 'beast,sidecar1,sidecar2',
    ], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect($config->dockerHosts)->toBe(['beast', 'sidecar1', 'sidecar2'])
            ->and($config->forHost('sidecar1')->dockerHosts)->toBe(['beast', 'sidecar1', 'sidecar2']);
    });
});

it('defaults docker max containers per host', function (): void {
    withE2EConfigEnvironment([], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect($config->dockerMaxContainersPerHost)->toBe(8)
            ->and($config->forHost('sidecar1')->dockerMaxContainersPerHost)->toBe(8);
    });
});
