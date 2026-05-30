<?php

declare(strict_types=1);

use App\E2E\Support\E2EDevTopologyManifestStore;

beforeEach(function (): void {
    $this->manifestDirectory = make_temp_directory('manifests');
});

afterEach(function (): void {
    remove_directory($this->manifestDirectory);
});

it('writes reads lists and removes retained topology manifests', function (): void {
    $store = new E2EDevTopologyManifestStore($this->manifestDirectory);

    $payload = [
        'id' => 'operator/dev topology',
        'provider' => 'docker',
        'host' => 'sidecar1',
        'kind' => 'operator_gateway_app-dev',
        'created_at' => '2026-05-29T10:00:00+00:00',
        'checkout_roles' => ['operator', 'gateway', 'app-dev'],
        'checkouts' => [
            'operator' => '/workspace/operator',
        ],
        'instances' => [
            ['role' => 'operator', 'name' => 'orbit-e2e-operator'],
        ],
        'cleanup_commands' => [
            'docker rm -f orbit-e2e-operator',
        ],
        'leases' => [
            [
                'backend' => 'docker',
                'host' => 'sidecar1',
                'slot' => 1,
                'path' => "{$this->manifestDirectory}/lease.json",
                'owner' => 'manual-debug-session',
                'retained' => true,
            ],
        ],
        'release_command' => 'composer e2e:dev-topology:release -- operator/dev topology',
    ];

    $store->write($payload);

    expect($store->read('operator/dev topology'))->toMatchArray($payload)
        ->and($store->list())->toHaveCount(1)
        ->and($store->list()[0])->toMatchArray($payload)
        ->and(glob("{$this->manifestDirectory}/*.json"))->toHaveCount(1)
        ->and(basename((glob("{$this->manifestDirectory}/*.json") ?: [''])[0]))->toBe('operator_dev_topology.json');

    $store->remove('operator/dev topology');

    expect($store->read('operator/dev topology'))->toBeNull()
        ->and($store->list())->toBe([]);
});

it('defaults manifest storage to the apps e2e var path', function (): void {
    $repoRoot = make_temp_directory('repo');

    try {
        $store = E2EDevTopologyManifestStore::fromRepositoryRoot($repoRoot);

        expect($store->directory())->toBe("{$repoRoot}/apps/e2e/var/dev-topology");
    } finally {
        remove_directory($repoRoot);
    }
});
