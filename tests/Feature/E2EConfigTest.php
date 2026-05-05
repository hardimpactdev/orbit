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

it('parses docker host slots for the lease pool', function (): void {
    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_HOST_SLOTS' => 'sidecar1:2, sidecar2:2, beast:3',
    ], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect($config->dockerHostSlots)->toBe([
            'sidecar1' => 2,
            'sidecar2' => 2,
            'beast' => 3,
        ])->and($config->forHost('beast')->dockerHostSlots)->toBe([
            'sidecar1' => 2,
            'sidecar2' => 2,
            'beast' => 3,
        ]);
    });
});

it('parses incus host slots for the lease pool', function (): void {
    withE2EConfigEnvironment([
        'ORBIT_E2E_INCUS_HOST_SLOTS' => 'sidecar1:1, sidecar2:2',
    ], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect($config->incusHostSlots)->toBe([
            'sidecar1' => 1,
            'sidecar2' => 2,
        ])->and($config->forHost('sidecar1')->incusHostSlots)->toBe([
            'sidecar1' => 1,
            'sidecar2' => 2,
        ]);
    });
});

it('parses hcloud location slots for the lease pool', function (): void {
    withE2EConfigEnvironment([
        'ORBIT_E2E_HCLOUD_LOCATION_SLOTS' => 'nbg1:2, fsn1:1',
    ], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect($config->hcloudLocationSlots)->toBe([
            'nbg1' => 2,
            'fsn1' => 1,
        ])->and($config->forHcloudLocation('fsn1')->hcloudLocationSlots)->toBe([
            'nbg1' => 2,
            'fsn1' => 1,
        ]);
    });
});

it('parses hcloud resource slots for the lease pool', function (): void {
    withE2EConfigEnvironment([
        'ORBIT_E2E_HCLOUD_RESOURCE_SLOTS' => 'nbg1/cx23/ubuntu-24.04:2, fsn1/cpx31/ubuntu-24.04:1',
    ], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect($config->hcloudResourceSlots)->toBe([
            'nbg1/cx23/ubuntu-24.04' => 2,
            'fsn1/cpx31/ubuntu-24.04' => 1,
        ]);
    });
});

it('defaults e2e slot wait and stale seconds', function (): void {
    withE2EConfigEnvironment([], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect($config->slotWaitSeconds)->toBe(900)
            ->and($config->slotStaleSeconds)->toBe(7200)
            ->and($config->forHost('sidecar1')->slotWaitSeconds)->toBe(900)
            ->and($config->forHost('sidecar1')->slotStaleSeconds)->toBe(7200);
    });
});

it('reads e2e slot wait and stale seconds from the environment', function (): void {
    withE2EConfigEnvironment([
        'ORBIT_E2E_SLOT_WAIT_SECONDS' => '30',
        'ORBIT_E2E_SLOT_STALE_SECONDS' => '120',
    ], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect($config->slotWaitSeconds)->toBe(30)
            ->and($config->slotStaleSeconds)->toBe(120);
    });
});
