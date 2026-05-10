<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EImage;
use App\E2E\Support\E2EInstance;
use App\E2E\Support\E2EProvider;
use App\E2E\Support\E2EResourceLeasePool;
use App\E2E\Support\E2ERun;
use App\E2E\Support\HcloudProvider;
use App\E2E\Support\IncusProvider;
use App\E2E\Support\ProviderAvailability;
use App\E2E\Support\ProviderPool;
use App\E2E\Support\SshKeyPair;
use Illuminate\Support\Facades\Process;

it('defaults to the incus provider', function (): void {
    withE2EProviderEnvironment([], function (): void {
        expect(E2EConfig::fromEnvironment()->providerNames)->toBe(['incus']);
    });
});

it('expands auto provider selection to incus only', function (): void {
    withE2EProviderEnvironment(['ORBIT_E2E_PROVIDER' => 'auto'], function (): void {
        expect(E2EConfig::fromEnvironment()->providerNames)->toBe(['incus']);
    });
});

it('uses the explicit provider list before the single provider value', function (): void {
    withE2EProviderEnvironment([
        'ORBIT_E2E_PROVIDER' => 'incus',
        'ORBIT_E2E_PROVIDERS' => 'hcloud, incus',
    ], function (): void {
        expect(E2EConfig::fromEnvironment()->providerNames)->toBe(['hcloud', 'incus']);
    });
});

it('reads the explicit incus storage pool from the environment', function (): void {
    withE2EProviderEnvironment(['ORBIT_E2E_INCUS_STORAGE_POOL' => 'orbit-e2e'], function (): void {
        expect(E2EConfig::fromEnvironment()->incusStoragePool)->toBe('orbit-e2e');
    });
});

it('reads the topology state size from the environment', function (): void {
    withE2EProviderEnvironment(['ORBIT_E2E_TOPOLOGY_STATE_SIZE' => '6GiB'], function (): void {
        expect(E2EConfig::fromEnvironment()->topologyStateSize)->toBe('6GiB');
    });
});

it('selects the first available provider', function (): void {
    $pool = new ProviderPool([
        fakeE2EProvider('incus', false),
        fakeE2EProvider('hcloud', true),
    ]);

    $selection = $pool->select(E2EImage::Blank);

    expect($selection->available())->toBeTrue()
        ->and($selection->provider()->name())->toBe('hcloud')
        ->and($selection->message)->toBe('hcloud: ready');
});

it('reports provider failures when no provider is available', function (): void {
    $pool = new ProviderPool([
        fakeE2EProvider('incus', false),
        fakeE2EProvider('hcloud', false),
    ]);

    $selection = $pool->select(E2EImage::Blank);

    expect($selection->available())->toBeFalse()
        ->and($selection->message)->toContain('incus: unavailable')
        ->and($selection->message)->toContain('hcloud: unavailable');
});

it('discovers the incus base image by logical image label', function (): void {
    Process::fake([
        '*command -v*incus*' => Process::result(),
        '*incus info*' => Process::result(),
        '*incus network show incusbr0*' => Process::result(),
        '*incus image info*orbit-base-ubuntu-26.04*' => Process::result(),
    ]);

    $availability = (new IncusProvider(E2EConfig::fromEnvironment()))
        ->availability([E2EImage::Base]);

    expect($availability->available)->toBeTrue();
});

it('leases configured incus host slots before checking availability', function (): void {
    $seenHost = null;
    $leaseDirectory = storage_path('framework/e2e/test-leases-'.bin2hex(random_bytes(4)));

    exec('rm -rf '.escapeshellarg($leaseDirectory));

    Process::fake(function ($process) use (&$seenHost) {
        if (str_contains($process->command, "'sidecar1'")) {
            $seenHost = 'sidecar1';
        }

        return Process::result();
    });

    try {
        withE2EProviderEnvironment([
            'ORBIT_E2E_INCUS_HOST_SLOTS' => 'sidecar1:1,sidecar2:1',
            'ORBIT_E2E_LEASE_DIRECTORY' => $leaseDirectory,
        ], function () use (&$seenHost): void {
            $provider = new IncusProvider(E2EConfig::fromEnvironment());

            $availability = $provider->availability([E2EImage::Base]);

            expect($availability->available)->toBeTrue()
                ->and($provider->config()->host)->toBe('sidecar1')
                ->and($seenHost)->toBe('sidecar1');
        });
    } finally {
        exec('rm -rf '.escapeshellarg($leaseDirectory));
    }
});

it('releases configured incus host slots during run cleanup', function (): void {
    $leaseDirectory = storage_path('framework/e2e/test-leases-'.bin2hex(random_bytes(4)));

    exec('rm -rf '.escapeshellarg($leaseDirectory));

    Process::fake([
        '*rm -rf*mkdir -p*' => Process::result(),
        '*rm -rf*' => Process::result(),
    ]);

    try {
        withE2EProviderEnvironment([
            'ORBIT_E2E_INCUS_HOST_SLOTS' => 'sidecar1:1',
            'ORBIT_E2E_LEASE_DIRECTORY' => $leaseDirectory,
            'ORBIT_E2E_SLOT_WAIT_SECONDS' => '0',
        ], function () use ($leaseDirectory): void {
            $provider = new IncusProvider(E2EConfig::fromEnvironment());
            $run = $provider->startRun('lease release');
            $pool = new E2EResourceLeasePool($leaseDirectory, waitSeconds: 0, staleSeconds: 60);

            expect($pool->snapshot('incus', ['sidecar1' => 1]))->toMatchArray([
                ['host' => 'sidecar1', 'slot' => 1, 'leased' => true],
            ]);

            $run->cleanup();

            expect($pool->snapshot('incus', ['sidecar1' => 1]))->toMatchArray([
                ['host' => 'sidecar1', 'slot' => 1, 'leased' => false],
            ]);
        });
    } finally {
        exec('rm -rf '.escapeshellarg($leaseDirectory));
    }
});

it('releases configured incus host slots after availability-only checks', function (): void {
    $leaseDirectory = storage_path('framework/e2e/test-leases-'.bin2hex(random_bytes(4)));

    exec('rm -rf '.escapeshellarg($leaseDirectory));

    Process::fake([
        '*command -v*incus*' => Process::result(),
        '*incus info*' => Process::result(),
        '*incus network show incusbr0*' => Process::result(),
        '*incus image info*orbit-base-ubuntu-26.04*' => Process::result(),
    ]);

    try {
        withE2EProviderEnvironment([
            'ORBIT_E2E_INCUS_HOST_SLOTS' => 'sidecar1:1',
            'ORBIT_E2E_LEASE_DIRECTORY' => $leaseDirectory,
            'ORBIT_E2E_SLOT_WAIT_SECONDS' => '0',
        ], function () use ($leaseDirectory): void {
            $provider = new IncusProvider(E2EConfig::fromEnvironment());
            $availability = $provider->availability([E2EImage::Base]);
            $pool = new E2EResourceLeasePool($leaseDirectory, waitSeconds: 0, staleSeconds: 60);

            expect($availability->available)->toBeTrue()
                ->and($pool->snapshot('incus', ['sidecar1' => 1]))->toMatchArray([
                    ['host' => 'sidecar1', 'slot' => 1, 'leased' => true],
                ]);

            unset($provider);
            gc_collect_cycles();

            expect($pool->snapshot('incus', ['sidecar1' => 1]))->toMatchArray([
                ['host' => 'sidecar1', 'slot' => 1, 'leased' => false],
            ]);
        });
    } finally {
        exec('rm -rf '.escapeshellarg($leaseDirectory));
    }
});

it('leases configured hcloud location slots before checking availability', function (): void {
    $leaseDirectory = storage_path('framework/e2e/test-leases-'.bin2hex(random_bytes(4)));

    exec('rm -rf '.escapeshellarg($leaseDirectory));

    Process::fake([
        'command -v hcloud >/dev/null' => Process::result(),
        'hcloud version' => Process::result(output: "hcloud v1.62.2\n"),
        'hcloud server-type describe * -o json >/dev/null' => Process::result(output: '{}'),
        'hcloud image describe --architecture=x86 * -o json >/dev/null' => Process::result(),
    ]);

    try {
        withE2EProviderEnvironment([
            'ORBIT_E2E_PROVIDER' => 'hcloud',
            'ORBIT_E2E_HCLOUD_LOCATION_SLOTS' => 'nbg1:1,fsn1:1',
            'ORBIT_E2E_LEASE_DIRECTORY' => $leaseDirectory,
        ], function (): void {
            $provider = new HcloudProvider(E2EConfig::fromEnvironment());

            $availability = $provider->availability([E2EImage::Blank]);

            expect($availability->available)->toBeTrue()
                ->and($provider->config()->hcloudLocation)->toBe('nbg1');
        });
    } finally {
        exec('rm -rf '.escapeshellarg($leaseDirectory));
    }
});

it('applies configured hcloud resource slots before checking availability', function (): void {
    $leaseDirectory = storage_path('framework/e2e/test-leases-'.bin2hex(random_bytes(4)));

    exec('rm -rf '.escapeshellarg($leaseDirectory));

    Process::fake([
        'command -v hcloud >/dev/null' => Process::result(),
        'hcloud version' => Process::result(output: "hcloud v1.62.2\n"),
        "hcloud server-type describe 'cx23' -o json >/dev/null" => Process::result(output: '{}'),
        "hcloud image describe --architecture=x86 'ubuntu-24.04' -o json >/dev/null" => Process::result(),
    ]);

    try {
        withE2EProviderEnvironment([
            'ORBIT_E2E_PROVIDER' => 'hcloud',
            'ORBIT_E2E_HCLOUD_RESOURCE_SLOTS' => 'nbg1/cx23/ubuntu-24.04:1,fsn1/cpx31/ubuntu-24.04:1',
            'ORBIT_E2E_LEASE_DIRECTORY' => $leaseDirectory,
        ], function (): void {
            $provider = new HcloudProvider(E2EConfig::fromEnvironment());

            $availability = $provider->availability([E2EImage::Blank]);

            expect($availability->available)->toBeTrue()
                ->and($provider->config()->hcloudLocation)->toBe('nbg1')
                ->and($provider->config()->hcloudServerType)->toBe('cx23')
                ->and($provider->config()->hcloudBlankImage)->toBe('ubuntu-24.04');
        });
    } finally {
        exec('rm -rf '.escapeshellarg($leaseDirectory));
    }
});

it('releases configured hcloud slots after availability-only checks', function (): void {
    $leaseDirectory = storage_path('framework/e2e/test-leases-'.bin2hex(random_bytes(4)));

    exec('rm -rf '.escapeshellarg($leaseDirectory));

    Process::fake([
        'command -v hcloud >/dev/null' => Process::result(),
        'hcloud version' => Process::result(output: "hcloud v1.62.2\n"),
        'hcloud server-type describe * -o json >/dev/null' => Process::result(output: '{}'),
        'hcloud image describe --architecture=x86 * -o json >/dev/null' => Process::result(),
    ]);

    try {
        withE2EProviderEnvironment([
            'ORBIT_E2E_PROVIDER' => 'hcloud',
            'ORBIT_E2E_HCLOUD_LOCATION_SLOTS' => 'nbg1:1',
            'ORBIT_E2E_LEASE_DIRECTORY' => $leaseDirectory,
            'ORBIT_E2E_SLOT_WAIT_SECONDS' => '0',
        ], function () use ($leaseDirectory): void {
            $provider = new HcloudProvider(E2EConfig::fromEnvironment());
            $availability = $provider->availability([E2EImage::Blank]);
            $pool = new E2EResourceLeasePool($leaseDirectory, waitSeconds: 0, staleSeconds: 60);

            expect($availability->available)->toBeTrue()
                ->and($pool->snapshot('hcloud', ['nbg1' => 1]))->toMatchArray([
                    ['host' => 'nbg1', 'slot' => 1, 'leased' => true],
                ]);

            unset($provider);
            gc_collect_cycles();

            expect($pool->snapshot('hcloud', ['nbg1' => 1]))->toMatchArray([
                ['host' => 'nbg1', 'slot' => 1, 'leased' => false],
            ]);
        });
    } finally {
        exec('rm -rf '.escapeshellarg($leaseDirectory));
    }
});

it('releases configured hcloud location slots during run cleanup', function (): void {
    $leaseDirectory = storage_path('framework/e2e/test-leases-'.bin2hex(random_bytes(4)));

    exec('rm -rf '.escapeshellarg($leaseDirectory));

    Process::fake([
        '*rm -rf*' => Process::result(),
    ]);

    try {
        withE2EProviderEnvironment([
            'ORBIT_E2E_PROVIDER' => 'hcloud',
            'ORBIT_E2E_HCLOUD_LOCATION_SLOTS' => 'nbg1:1',
            'ORBIT_E2E_LEASE_DIRECTORY' => $leaseDirectory,
            'ORBIT_E2E_SLOT_WAIT_SECONDS' => '0',
        ], function () use ($leaseDirectory): void {
            $provider = new HcloudProvider(E2EConfig::fromEnvironment());
            $run = $provider->startRun('hcloud lease release');
            $pool = new E2EResourceLeasePool($leaseDirectory, waitSeconds: 0, staleSeconds: 60);

            expect($pool->snapshot('hcloud', ['nbg1' => 1]))->toMatchArray([
                ['host' => 'nbg1', 'slot' => 1, 'leased' => true],
            ]);

            $run->cleanup();

            expect($pool->snapshot('hcloud', ['nbg1' => 1]))->toMatchArray([
                ['host' => 'nbg1', 'slot' => 1, 'leased' => false],
            ]);
        });
    } finally {
        exec('rm -rf '.escapeshellarg($leaseDirectory));
    }
});

function fakeE2EProvider(string $name, bool $available): E2EProvider
{
    return new class($name, $available) implements E2EProvider
    {
        public function __construct(
            private readonly string $name,
            private readonly bool $available,
        ) {}

        public function name(): string
        {
            return $this->name;
        }

        public function config(): E2EConfig
        {
            return E2EConfig::fromEnvironment();
        }

        /**
         * @param  list<E2EImage>  $images
         */
        public function availability(array $images): ProviderAvailability
        {
            return $this->available
                ? ProviderAvailability::available('ready')
                : ProviderAvailability::unavailable('unavailable');
        }

        public function startRun(string $label): E2ERun
        {
            throw new RuntimeException('Fake provider cannot start runs.');
        }

        public function createSshKeyPair(E2ERun $run): SshKeyPair
        {
            throw new RuntimeException('Fake provider cannot create keys.');
        }

        public function launch(E2ERun $run, E2EImage $image, string $suffix): E2EInstance
        {
            throw new RuntimeException('Fake provider cannot launch instances.');
        }

        /**
         * @param  list<E2EInstance>  $instances
         */
        public function cleanup(E2ERun $run, array $instances): void
        {
            throw new RuntimeException('Fake provider cannot clean up.');
        }
    };
}
