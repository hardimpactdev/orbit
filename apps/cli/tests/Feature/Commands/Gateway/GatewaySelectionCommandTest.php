<?php

declare(strict_types=1);

use App\Services\OrbitConfigStore;

/**
 * @return array{OrbitConfigStore, string}
 */
function setupGatewaySelectionStore(): array
{
    $tempDir = sys_get_temp_dir().'/orbit-gateway-selection-test-'.bin2hex(random_bytes(4));
    $configPath = $tempDir.'/config.json';

    mkdir($tempDir.'/gateways/default', 0700, true);
    mkdir($tempDir.'/gateways/incus-dev', 0700, true);

    $store = new OrbitConfigStore(overridePath: $configPath);
    $store->save([
        'active_gateway' => 'default',
        'gateways' => [
            'default' => [
                'url' => 'https://10.6.0.2',
                'wireguard_ip' => '10.6.0.2',
                'ca_pem_path' => $tempDir.'/gateways/default/ca.pem',
                'ca_sha256' => 'default-sha',
                'ca_fingerprint' => 'sha256:default-sha',
                'timeout' => 30,
                'self_mode' => OrbitConfigStore::DEFAULT_SELF_MODE,
            ],
            'incus-dev' => [
                'url' => 'https://10.6.0.12',
                'wireguard_ip' => '10.6.0.12',
                'ca_pem_path' => $tempDir.'/gateways/incus-dev/ca.pem',
                'ca_sha256' => 'incus-sha',
                'ca_fingerprint' => 'sha256:incus-sha',
                'timeout' => 30,
                'self_mode' => OrbitConfigStore::DEFAULT_SELF_MODE,
            ],
        ],
        'defaults' => ['node' => null, 'profile' => null],
        'meta' => ['imported_from' => null, 'imported_at' => null],
    ]);

    app()->instance(OrbitConfigStore::class, $store);

    return [$store, $tempDir];
}

function cleanupGatewaySelectionStore(string $tempDir): void
{
    foreach (['default', 'incus-dev'] as $name) {
        foreach (glob($tempDir."/gateways/{$name}/*") ?: [] as $path) {
            @unlink($path);
        }

        @rmdir($tempDir."/gateways/{$name}");
    }

    @rmdir($tempDir.'/gateways');
    @unlink($tempDir.'/config.json');
    @rmdir($tempDir);
}

describe('gateway:list', function (): void {
    it('lists configured gateways and marks the active one', function (): void {
        [, $tempDir] = setupGatewaySelectionStore();

        [$exitCode, $output] = runCommand($this, 'gateway:list', ['--json' => true]);
        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['active_gateway'])->toBe('default')
            ->and($decoded['success']['data']['gateways'])->toHaveCount(2)
            ->and($decoded['success']['data']['gateways'][0]['name'])->toBe('default')
            ->and($decoded['success']['data']['gateways'][0]['active'])->toBeTrue()
            ->and($decoded['success']['data']['gateways'][1]['name'])->toBe('incus-dev')
            ->and($decoded['success']['data']['gateways'][1]['active'])->toBeFalse();

        cleanupGatewaySelectionStore($tempDir);
    });

    it('fails when no gateways are configured', function (): void {
        $tempDir = sys_get_temp_dir().'/orbit-gateway-empty-test-'.bin2hex(random_bytes(4));
        $store = new OrbitConfigStore(overridePath: $tempDir.'/config.json');
        app()->instance(OrbitConfigStore::class, $store);

        [$exitCode, $output] = runCommand($this, 'gateway:list', ['--json' => true]);
        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['reason'])->toBe('missing');

        @rmdir($tempDir);
    });
});

describe('gateway:use', function (): void {
    it('switches the active gateway to an existing configured name', function (): void {
        [$store, $tempDir] = setupGatewaySelectionStore();

        [$exitCode, $output] = runCommand($this, 'gateway:use', [
            'name' => 'incus-dev',
            '--json' => true,
        ]);
        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);
        $config = $store->read();

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['result']['action'])->toBe('selected')
            ->and($decoded['success']['data']['gateway']['name'])->toBe('incus-dev')
            ->and($config['active_gateway'])->toBe('incus-dev');

        cleanupGatewaySelectionStore($tempDir);
    });

    it('fails when the requested gateway is unknown', function (): void {
        [, $tempDir] = setupGatewaySelectionStore();

        [$exitCode, $output] = runCommand($this, 'gateway:use', [
            'name' => 'missing',
            '--json' => true,
        ]);
        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['reason'])->toBe('not_found');

        cleanupGatewaySelectionStore($tempDir);
    });
});
