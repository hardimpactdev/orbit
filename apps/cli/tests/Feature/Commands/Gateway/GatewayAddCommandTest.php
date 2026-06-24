<?php

declare(strict_types=1);

use App\Enums\Trust\TrustStoreInstallReason;
use App\Services\Gateway\FetchesGatewayRootCa;
use App\Services\Gateway\RootCaFetchResult;
use App\Services\Gateway\VerifiesGatewayIdentity;
use App\Services\OrbitConfigStore;
use App\Services\Trust\TrustStoreInstaller;
use App\Services\Trust\TrustStoreInstallException;
use App\Services\WireGuard\ResolvesGatewayAddress;
use Illuminate\Http\Client\ConnectionException;

const ADD_FAKE_PEM = "-----BEGIN CERTIFICATE-----\nMIIFake==\n-----END CERTIFICATE-----\n";

// ---------------------------------------------------------------------------
// Test-only fakes
// ---------------------------------------------------------------------------

final class FakeTrustStoreInstaller implements TrustStoreInstaller
{
    public bool $trusted = false;

    public bool $throwUnsupported = false;

    public bool $throwCommandFailed = false;

    public function isCaTrusted(string $rootCaPath, string $label): bool
    {
        return $this->trusted;
    }

    public function trustCa(string $rootCaPath, string $label, ?Closure $log = null): void
    {
        if ($this->throwUnsupported) {
            throw new TrustStoreInstallException('unsupported', TrustStoreInstallReason::UnsupportedPlatform);
        }

        if ($this->throwCommandFailed) {
            throw new TrustStoreInstallException('failed', TrustStoreInstallReason::CommandFailed);
        }

        $this->trusted = true;
    }
}

final class FakeFetchGatewayRootCa implements FetchesGatewayRootCa
{
    public ?RootCaFetchResult $result = null;

    public ?Throwable $throws = null;

    public function handle(string $gatewayIp): RootCaFetchResult
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }

        return (
            $this->result ?? new RootCaFetchResult(
                ADD_FAKE_PEM,
                hash('sha256', ADD_FAKE_PEM),
                "https://{$gatewayIp}/api/ca/root",
            )
        );
    }
}

final class FakeVerifyGatewayIdentity implements VerifiesGatewayIdentity
{
    /** @var array<string, mixed>|null */
    public ?array $result = null;

    public function handle(string $gatewayIp, string $pemPath): array
    {
        return (
            $this->result ?? [
                'gateway_name' => 'gateway-1',
                'gateway_ip' => $gatewayIp,
                'gateway_status' => 'active',
                'gateway_platform' => 'linux',
                'local_node_name' => 'client-1',
                'local_node_status' => 'active',
                'local_node_platform' => 'darwin',
                'local_node_wg_ip' => '10.6.0.5',
            ]
        );
    }
}

final class FakeWireGuardGatewayAddressResolver implements ResolvesGatewayAddress
{
    public ?string $resolvedIp = null;

    public function resolve(): ?string
    {
        return $this->resolvedIp;
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * @return array{OrbitConfigStore, FakeTrustStoreInstaller, FakeFetchGatewayRootCa, FakeVerifyGatewayIdentity, FakeWireGuardGatewayAddressResolver, string} [$store, $installer, $fetch, $verify, $resolver, $tempDir]
 */
function setupAddFakes(bool $converged = false): array
{
    $tempDir = sys_get_temp_dir().'/orbit-add-test-'.bin2hex(random_bytes(4));
    mkdir($tempDir.'/gateways/default', 0700, true);
    $tempConfigPath = $tempDir.'/config.json';
    $tempPemPath = $tempDir.'/gateways/default/ca.pem';
    $fakeSha256 = hash('sha256', ADD_FAKE_PEM);

    $store = new OrbitConfigStore(overridePath: $tempConfigPath);

    if ($converged) {
        file_put_contents($tempPemPath, ADD_FAKE_PEM);
        chmod($tempPemPath, 0600);
        $config = $store->emptySkeleton();
        $config['active_gateway'] = 'default';
        $config['gateways']['default'] = [
            'url' => 'https://10.6.0.2',
            'wireguard_ip' => '10.6.0.2',
            'ca_pem_path' => $tempPemPath,
            'ca_sha256' => $fakeSha256,
            'trusted_at' => now()->toIso8601String(),
        ];
        $store->save($config);
    }

    app()->instance(OrbitConfigStore::class, $store);

    $fetch = new FakeFetchGatewayRootCa;
    app()->instance(FetchesGatewayRootCa::class, $fetch);

    $installer = new FakeTrustStoreInstaller;
    if ($converged) {
        $installer->trusted = true;
    }
    app()->instance(TrustStoreInstaller::class, $installer);

    $verify = new FakeVerifyGatewayIdentity;
    app()->instance(VerifiesGatewayIdentity::class, $verify);

    $resolver = new FakeWireGuardGatewayAddressResolver;
    app()->instance(ResolvesGatewayAddress::class, $resolver);

    return [$store, $installer, $fetch, $verify, $resolver, $tempDir];
}

function cleanupAdd(string $tempDir): void
{
    $paths = glob($tempDir.'/{,.}[!.,!..]*', GLOB_BRACE) ?: [];

    foreach (array_reverse($paths) as $path) {
        if (is_dir($path)) {
            foreach (glob($path.'/*') ?: [] as $child) {
                @unlink($child);
            }

            @rmdir($path);

            continue;
        }

        @unlink($path);
    }

    @rmdir($tempDir);
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('gateway:add', function (): void {
    it('returns validation_failed when gateway_ip is missing and cannot be derived', function (): void {
        setupAddFakes();

        [$exitCode, $output] = runCommand($this, 'gateway:add', ['--json' => true]);
        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('gateway_ip')
            ->and($decoded['error']['meta']['reason'])
            ->toBe('missing');
    });

    it('returns validation_failed for an invalid WireGuard IP', function (): void {
        setupAddFakes();

        [$exitCode, $output] = runCommand($this, 'gateway:add', [
            'gateway_ip' => '192.168.1.1',
            '--json' => true,
        ]);
        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['reason'])
            ->toBe('invalid_ip');
    });

    it('returns gateway_unavailable when CA fetch fails with connection error', function (): void {
        [, , $fetch] = setupAddFakes();
        $fetch->throws = new ConnectionException('connection refused');

        [$exitCode, $output] = runCommand($this, 'gateway:add', [
            'gateway_ip' => '10.6.0.2',
            '--json' => true,
        ]);
        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('gateway_unavailable');
    });

    it('returns node.gateway_api_error when CA is invalid PEM', function (): void {
        [, , $fetch] = setupAddFakes();
        $fetch->throws = new RuntimeException('Gateway at 10.6.0.2 returned non-PEM content.');

        [$exitCode, $output] = runCommand($this, 'gateway:add', [
            'gateway_ip' => '10.6.0.2',
            '--json' => true,
        ]);
        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('node.gateway_api_error');
    });

    it('returns node.unsupported_platform when trust store is not supported', function (): void {
        [, $installer, , , , $tempDir] = setupAddFakes();
        $installer->throwUnsupported = true;

        [$exitCode, $output] = runCommand($this, 'gateway:add', [
            'gateway_ip' => '10.6.0.2',
            '--json' => true,
        ]);
        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('node.unsupported_platform');

        cleanupAdd($tempDir);
    });

    it('returns node.identity_unknown when /api/me returns 403', function (): void {
        [, , , $verify, , $tempDir] = setupAddFakes();
        $verify->result = [
            'code' => 'node.identity_unknown',
            'message' => 'Peer not registered.',
            'meta' => ['gateway_ip' => '10.6.0.2'],
        ];

        [$exitCode, $output] = runCommand($this, 'gateway:add', [
            'gateway_ip' => '10.6.0.2',
            '--json' => true,
        ]);
        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('node.identity_unknown');

        cleanupAdd($tempDir);
    });

    it('returns a canonical success envelope with action=added on first add', function (): void {
        [, , , , , $tempDir] = setupAddFakes();

        [$exitCode, $output] = runCommand($this, 'gateway:add', [
            'gateway_ip' => '10.6.0.2',
            '--json' => true,
        ]);
        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['result']['action'])
            ->toBe('added')
            ->and($decoded['success']['data']['gateway']['name'])
            ->toBe('gateway-1')
            ->and($decoded['success']['data']['local_node']['name'])
            ->toBe('client-1')
            ->and($decoded['success']['data']['local_onboarding']['gateway_trust'])
            ->toBe('trusted')
            ->and($decoded['success']['data']['local_onboarding']['gateway_config'])
            ->toBe('stored');

        cleanupAdd($tempDir);
    });

    it('stores a named gateway without overwriting the existing default entry', function (): void {
        [$store, , , , , $tempDir] = setupAddFakes();
        $config = $store->emptySkeleton();
        $config['active_gateway'] = 'default';
        $config['gateways']['default'] = [
            'url' => 'https://10.6.0.9',
            'wireguard_ip' => '10.6.0.9',
            'ca_pem_path' => $tempDir.'/gateways/default/ca.pem',
            'ca_sha256' => 'default-sha',
            'ca_fingerprint' => 'sha256:default-sha',
            'timeout' => OrbitConfigStore::DEFAULT_TIMEOUT_SECONDS,
            'self_mode' => OrbitConfigStore::DEFAULT_SELF_MODE,
        ];
        $store->save($config);

        [$exitCode, $output] = runCommand($this, 'gateway:add', [
            'gateway_ip' => '10.6.0.2',
            '--name' => 'incus-dev',
            '--json' => true,
        ]);
        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);
        $config = $store->read();

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['result']['action'])
            ->toBe('added')
            ->and($config['active_gateway'])
            ->toBe('incus-dev')
            ->and($config['gateways']['default']['wireguard_ip'])
            ->toBe('10.6.0.9')
            ->and($config['gateways']['incus-dev']['wireguard_ip'])
            ->toBe('10.6.0.2')
            ->and($config['gateways']['incus-dev']['ca_pem_path'])
            ->toContain('/gateways/incus-dev/ca.pem');

        cleanupAdd($tempDir);
    });

    it('returns validation_failed for an invalid gateway name', function (): void {
        [, , , , , $tempDir] = setupAddFakes();

        [$exitCode, $output] = runCommand($this, 'gateway:add', [
            'gateway_ip' => '10.6.0.2',
            '--name' => '../prod',
            '--json' => true,
        ]);
        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('name')
            ->and($decoded['error']['meta']['reason'])
            ->toBe('invalid_name');

        cleanupAdd($tempDir);
    });

    it('returns success with action=converged when already configured', function (): void {
        [, , , $verify, , $tempDir] = setupAddFakes(converged: true);

        [$exitCode, $output] = runCommand($this, 'gateway:add', [
            'gateway_ip' => '10.6.0.2',
            '--json' => true,
        ]);
        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['result']['action'])
            ->toBe('converged')
            ->and($decoded['success']['data']['local_onboarding']['gateway_trust'])
            ->toBe('already_trusted');

        cleanupAdd($tempDir);
    });

    it('derives gateway IP from WireGuard resolver when argument is omitted', function (): void {
        [, , , , $resolver, $tempDir] = setupAddFakes();
        $resolver->resolvedIp = '10.6.0.2';

        [$exitCode, $output] = runCommand($this, 'gateway:add', ['--json' => true]);
        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)->and($decoded['success']['data']['result']['action'])->toBe('added');

        cleanupAdd($tempDir);
    });

    it('renders human output as a progress tree with gateway, node, and added footer', function (): void {
        [, , , , , $tempDir] = setupAddFakes();

        [$exitCode, $output] = runCommand($this, 'gateway:add', ['gateway_ip' => '10.6.0.2']);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Joining Gateway')
            ->and($output)
            ->toContain('Store local config')
            ->and($output)
            ->toContain("Joined gateway 'gateway-1'")
            ->and($output)
            ->toContain('Local node: client-1')
            ->and($output)
            ->toContain('Action: added')
            ->and($output)
            ->not->toContain('result:')->and($output)
            ->not->toContain('local_onboarding')->and($output)
            ->not->toContain('{');

        cleanupAdd($tempDir);
    });

    it('renders the converged footer in human mode when already configured', function (): void {
        [, , , , , $tempDir] = setupAddFakes(converged: true);

        [$exitCode, $output] = runCommand($this, 'gateway:add', ['gateway_ip' => '10.6.0.2']);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Joining Gateway')
            ->and($output)
            ->toContain('Action: converged')
            ->and($output)
            ->not->toContain('{');

        cleanupAdd($tempDir);
    });

    it('renders gateway:add validation failures as prose in human mode', function (): void {
        setupAddFakes();

        [$exitCode, $output] = runCommand($this, 'gateway:add', ['gateway_ip' => '192.168.1.1']);

        expect($exitCode)
            ->toBe(1)
            ->and($output)
            ->toContain('Gateway IP must be a valid Orbit WireGuard address.')
            ->and($output)
            ->not->toContain('"error"')->and($output)
            ->not->toContain('{');
    });

    it('renders gateway:add gateway-unavailable failures as prose in human mode', function (): void {
        [, , $fetch, , , $tempDir] = setupAddFakes();
        $fetch->throws = new ConnectionException('connection refused');

        [$exitCode, $output] = runCommand($this, 'gateway:add', ['gateway_ip' => '10.6.0.2']);

        expect($exitCode)
            ->toBe(1)
            ->and($output)
            ->toContain('Could not fetch the gateway CA from 10.6.0.2.')
            ->and($output)
            ->not->toContain('"error"');

        cleanupAdd($tempDir);
    });
});
