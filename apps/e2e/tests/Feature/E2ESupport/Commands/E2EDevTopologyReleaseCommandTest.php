<?php

declare(strict_types=1);

use App\E2E\Support\E2EDevTopologyManifestStore;
use App\E2E\Support\E2EResourceLeasePool;

beforeEach(function (): void {
    $this->manifestDirectory = make_temp_directory('release-manifests');
    $this->leaseDirectory = make_temp_directory('release-leases');
});

afterEach(function (): void {
    remove_directory($this->manifestDirectory);
    remove_directory($this->leaseDirectory);
});

it('removes manifests and releases retained lease metadata', function (): void {
    $store = new E2EDevTopologyManifestStore($this->manifestDirectory);
    $pool = new E2EResourceLeasePool($this->leaseDirectory, waitSeconds: 0, staleSeconds: 3600);
    $retainedLease = $pool->acquire('docker', ['sidecar1' => 1])->retain('release-test');
    $cleanupMarker = "{$this->manifestDirectory}/cleanup-marker.txt";
    $cleanupCommand = PHP_BINARY.' -r '.escapeshellarg("file_put_contents('{$cleanupMarker}', 'released');");

    $store->write([
        'id' => 'retained-one',
        'provider' => 'docker',
        'host' => 'sidecar1',
        'kind' => 'operator_gateway_app-dev',
        'created_at' => '2026-05-29T10:00:00+00:00',
        'checkout_roles' => ['operator', 'gateway', 'app-dev'],
        'checkouts' => [],
        'instances' => [],
        'cleanup_commands' => [$cleanupCommand],
        'leases' => [$retainedLease->metadata()],
        'release_command' => 'composer e2e:dev-topology:release -- retained-one',
    ]);

    $result = run_e2e_script(
        [PHP_BINARY, 'bin/e2e-dev-topology-release', 'retained-one', '--json'],
        env: ['ORBIT_E2E_DEV_TOPOLOGY_MANIFEST_DIRECTORY' => $this->manifestDirectory],
    );

    expect($result['exit_code'])->toBe(0, $result['stderr'])
        ->and($store->read('retained-one'))->toBeNull()
        ->and($cleanupMarker)->toBeFile()
        ->and($retainedLease->path())->not->toBeFile();

    $payload = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);

    expect($payload)->toMatchArray([
        'success' => [
            'released' => [
                'id' => 'retained-one',
                'cleanup_commands' => 1,
                'leases' => 1,
            ],
        ],
    ]);
});

it('fails clearly when a retained topology manifest is missing', function (): void {
    $result = run_e2e_script(
        [PHP_BINARY, 'bin/e2e-dev-topology-release', 'missing-topology', '--json'],
        env: ['ORBIT_E2E_DEV_TOPOLOGY_MANIFEST_DIRECTORY' => $this->manifestDirectory],
    );

    expect($result['exit_code'])->toBe(1);

    $payload = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);

    expect($payload)->toMatchArray([
        'error' => [
            'code' => 'not_found',
            'message' => 'Retained E2E topology [missing-topology] was not found.',
        ],
    ]);
});
