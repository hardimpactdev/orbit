<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Tests\E2E\Support\E2EConfig;
use Tests\E2E\Support\E2EImage;
use Tests\E2E\Support\E2EInstance;
use Tests\E2E\Support\E2EProvider;
use Tests\E2E\Support\E2ERun;
use Tests\E2E\Support\HcloudProvider;
use Tests\E2E\Support\ProviderAvailability;
use Tests\E2E\Support\ProviderPool;
use Tests\E2E\Support\SshKeyPair;

it('defaults to the incus provider', function (): void {
    withE2EProviderEnvironment([], function (): void {
        expect(E2EConfig::fromEnvironment()->providerNames)->toBe(['incus']);
    });
});

it('expands auto provider selection to incus and hcloud', function (): void {
    withE2EProviderEnvironment(['ORBIT_E2E_PROVIDER' => 'auto'], function (): void {
        expect(E2EConfig::fromEnvironment()->providerNames)->toBe(['incus', 'hcloud']);
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

    $selection = $pool->select(E2EImage::Control);

    expect($selection->available())->toBeFalse()
        ->and($selection->message)->toContain('incus: unavailable')
        ->and($selection->message)->toContain('hcloud: unavailable');
});

it('discovers prepared hcloud snapshots by logical image label', function (): void {
    Process::fake([
        'command -v hcloud >/dev/null' => Process::result(),
        'hcloud version' => Process::result(output: "hcloud v1.62.2\n"),
        'hcloud server-type describe *' => Process::result(output: '{}'),
        'hcloud image describe --architecture=x86 ubuntu-24.04 -o json >/dev/null' => Process::result(),
        'hcloud image list --selector *control*' => Process::result(output: json_encode([
            ['id' => 401, 'description' => 'orbit-ready-control-old', 'created' => '2026-05-03T08:00:00Z'],
            ['id' => 402, 'description' => 'orbit-ready-control', 'created' => '2026-05-03T09:00:00Z'],
        ], JSON_THROW_ON_ERROR)),
        'hcloud image list --selector *gateway*' => Process::result(output: json_encode([
            ['id' => 501, 'description' => 'orbit-ready-gateway', 'created' => '2026-05-03T09:00:00Z'],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $provider = new HcloudProvider(E2EConfig::fromEnvironment());

    expect($provider->availability([E2EImage::Blank, E2EImage::Control, E2EImage::Gateway])->available)->toBeTrue()
        ->and($provider->imageReferenceFor(E2EImage::Control))->toBe('402')
        ->and($provider->imageReferenceFor(E2EImage::Gateway))->toBe('501');
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

/**
 * @param  array<string, string>  $values
 */
function withE2EProviderEnvironment(array $values, Closure $callback): void
{
    $keys = ['ORBIT_E2E_PROVIDER', 'ORBIT_E2E_PROVIDERS'];
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
