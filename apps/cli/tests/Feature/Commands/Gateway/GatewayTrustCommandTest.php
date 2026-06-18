<?php

declare(strict_types=1);

use App\Enums\Trust\TrustStoreInstallReason;
use App\Services\Gateway\FetchesGatewayRootCa;
use App\Services\Gateway\RootCaFetchResult;
use App\Services\OrbitConfigStore;
use App\Services\Trust\TrustStoreInstaller;
use App\Services\Trust\TrustStoreInstallException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

const TRUST_FAKE_PEM = "-----BEGIN CERTIFICATE-----\nMIITrustFake==\n-----END CERTIFICATE-----\n";

// ---------------------------------------------------------------------------
// Test-only fakes
// ---------------------------------------------------------------------------

final class TrustFakeTrustStoreInstaller implements TrustStoreInstaller
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

final class TrustFakeFetchGatewayRootCa implements FetchesGatewayRootCa
{
    public ?RootCaFetchResult $result = null;

    public ?Throwable $throws = null;

    public function handle(string $gatewayIp): RootCaFetchResult
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->result ?? new RootCaFetchResult(
            TRUST_FAKE_PEM,
            hash('sha256', TRUST_FAKE_PEM),
            "https://{$gatewayIp}/api/ca/root",
        );
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * @return array{OrbitConfigStore, TrustFakeTrustStoreInstaller, TrustFakeFetchGatewayRootCa, string, string}
 */
function setupTrustFakes(bool $withGateway = true, bool $alreadyTrusted = false): array
{
    $tempDir = sys_get_temp_dir().'/orbit-trust-test-'.bin2hex(random_bytes(4));
    mkdir($tempDir.'/gateways/default', 0700, true);
    $tempConfigPath = $tempDir.'/config.json';
    $tempPemPath = $tempDir.'/gateways/default/ca.pem';
    $fakeSha256 = hash('sha256', TRUST_FAKE_PEM);

    $store = new OrbitConfigStore(overridePath: $tempConfigPath);

    if ($withGateway) {
        $config = $store->emptySkeleton();
        $config['active_gateway'] = 'default';
        $config['gateways']['default'] = [
            'url' => 'https://10.6.0.2',
            'wireguard_ip' => '10.6.0.2',
            'ca_pem_path' => $tempPemPath,
            'ca_sha256' => $alreadyTrusted ? $fakeSha256 : null,
            'trusted_at' => $alreadyTrusted ? now()->toIso8601String() : null,
        ];
        $store->save($config);
    }

    app()->instance(OrbitConfigStore::class, $store);

    $fetch = new TrustFakeFetchGatewayRootCa;
    app()->instance(FetchesGatewayRootCa::class, $fetch);

    $installer = new TrustFakeTrustStoreInstaller;
    $installer->trusted = $alreadyTrusted;
    app()->instance(TrustStoreInstaller::class, $installer);

    return [$store, $installer, $fetch, $tempDir, $tempPemPath];
}

function cleanupTrust(string $tempDir): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tempDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $path) {
        if ($path->isDir()) {
            @rmdir($path->getPathname());

            continue;
        }

        @unlink($path->getPathname());
    }

    @rmdir($tempDir);
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('gateway:trust', function (): void {
    it('returns validation_failed when no gateway is configured', function (): void {
        setupTrustFakes(withGateway: false);

        [$exitCode, $output] = runCommand($this, 'gateway:trust', ['--json' => true]);
        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['reason'])->toBe('missing');
    });

    it('returns gateway_unavailable when CA fetch fails with connection error', function (): void {
        [, , $fetch] = setupTrustFakes();
        $fetch->throws = new ConnectionException('connection refused');

        [$exitCode, $output] = runCommand($this, 'gateway:trust', ['--json' => true]);
        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unavailable');
    });

    it('returns node.gateway_api_error when CA material is invalid', function (): void {
        [, , $fetch] = setupTrustFakes();
        $fetch->throws = new RuntimeException('Gateway at 10.6.0.2 returned non-PEM content.');

        [$exitCode, $output] = runCommand($this, 'gateway:trust', ['--json' => true]);
        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('node.gateway_api_error');
    });

    it('returns node.unsupported_platform when trust store is unsupported', function (): void {
        [, $installer, , $tempDir] = setupTrustFakes();
        $installer->throwUnsupported = true;

        [$exitCode, $output] = runCommand($this, 'gateway:trust', ['--json' => true]);
        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('node.unsupported_platform');

        cleanupTrust($tempDir);
    });

    it('returns success with status=trusted after installing CA', function (): void {
        [, , , $tempDir] = setupTrustFakes();
        $fakeSha256 = hash('sha256', TRUST_FAKE_PEM);

        [$exitCode, $output] = runCommand($this, 'gateway:trust', ['--json' => true]);
        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['gateway_trust']['status'])->toBe('trusted')
            ->and($decoded['success']['data']['gateway_trust']['trusted'])->toBeTrue()
            ->and($decoded['success']['data']['gateway_trust']['gateway_url'])->toBe('https://10.6.0.2')
            ->and($decoded['success']['data']['gateway_trust']['ca_sha256'])->toBe($fakeSha256);

        cleanupTrust($tempDir);
    });

    it('repairs trust material under the active named gateway entry', function (): void {
        [$store, , , $tempDir] = setupTrustFakes();
        mkdir($tempDir.'/gateways/incus-dev', 0700, true);
        $config = $store->read();
        $config['active_gateway'] = 'incus-dev';
        $config['gateways']['incus-dev'] = [
            'url' => 'https://10.6.0.12',
            'wireguard_ip' => '10.6.0.12',
            'ca_pem_path' => $tempDir.'/gateways/incus-dev/ca.pem',
            'ca_sha256' => null,
            'trusted_at' => null,
        ];
        $store->save($config);

        [$exitCode] = runCommand($this, 'gateway:trust', ['--json' => true]);
        $config = $store->read();

        expect($exitCode)->toBe(0)
            ->and($config['active_gateway'])->toBe('incus-dev')
            ->and($config['gateways']['incus-dev']['ca_pem_path'])->toContain('/gateways/incus-dev/ca.pem')
            ->and($config['gateways']['default']['ca_pem_path'])->toContain('/gateways/default/ca.pem');

        cleanupTrust($tempDir);
    });

    it('returns success with status=already_trusted on idempotent re-run', function (): void {
        [, , , $tempDir, $tempPemPath] = setupTrustFakes(alreadyTrusted: true);
        $fakeSha256 = hash('sha256', TRUST_FAKE_PEM);

        // Write the PEM so persistPem path resolves correctly and isAlreadyTrusted can check
        file_put_contents($tempPemPath, TRUST_FAKE_PEM);

        [$exitCode, $output] = runCommand($this, 'gateway:trust', ['--json' => true]);
        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['gateway_trust']['status'])->toBe('already_trusted');

        cleanupTrust($tempDir);
    });

    it('renders human output as a progress tree with the trusted footer', function (): void {
        [, , , $tempDir] = setupTrustFakes();

        [$exitCode, $output] = runCommand($this, 'gateway:trust');

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Trusting Gateway CA')
            ->and($output)->toContain('Install local trust')
            ->and($output)->toContain('Gateway CA trusted for https://10.6.0.2.')
            ->and($output)->not->toContain('gateway_trust:')
            ->and($output)->not->toContain('{');

        cleanupTrust($tempDir);
    });

    it('renders the already-trusted footer in human mode on idempotent re-run', function (): void {
        [, , , $tempDir, $tempPemPath] = setupTrustFakes(alreadyTrusted: true);
        file_put_contents($tempPemPath, TRUST_FAKE_PEM);

        [$exitCode, $output] = runCommand($this, 'gateway:trust');

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Trusting Gateway CA')
            ->and($output)->toContain('Gateway CA already trusted for https://10.6.0.2.')
            ->and($output)->not->toContain('{');

        cleanupTrust($tempDir);
    });

    it('renders gateway:trust missing-gateway failures as prose in human mode', function (): void {
        setupTrustFakes(withGateway: false);

        [$exitCode, $output] = runCommand($this, 'gateway:trust');

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('No gateway is configured. Run orbit gateway:add first.')
            ->and($output)->not->toContain('"error"')
            ->and($output)->not->toContain('{');
    });

    it('renders gateway:trust gateway-unavailable failures as prose in human mode', function (): void {
        [, , $fetch, $tempDir] = setupTrustFakes();
        $fetch->throws = new ConnectionException('connection refused');

        [$exitCode, $output] = runCommand($this, 'gateway:trust');

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Could not fetch the gateway CA from https://10.6.0.2.')
            ->and($output)->not->toContain('"error"');

        cleanupTrust($tempDir);
    });

    it('does not verify /api/me identity', function (): void {
        [, , , $tempDir] = setupTrustFakes();

        Http::fake(['*/api/me' => Http::response([], 200)]);

        [$exitCode] = runCommand($this, 'gateway:trust', ['--json' => true]);

        Http::assertNothingSent();

        expect($exitCode)->toBe(0);

        cleanupTrust($tempDir);
    });
});
