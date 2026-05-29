<?php

declare(strict_types=1);

use App\E2E\Support\E2EResourceLeasePool;

beforeEach(function (): void {
    $this->leaseDirectory = make_temp_directory('leases');
});

afterEach(function (): void {
    remove_directory($this->leaseDirectory);
});

it('retains resource lease metadata until explicit release', function (): void {
    $pool = new E2EResourceLeasePool($this->leaseDirectory, waitSeconds: 0, staleSeconds: 0);

    $lease = $pool->acquire('docker', ['sidecar1' => 1]);
    $retained = $lease->retain('manual-debug-session');

    $payload = json_decode((string) file_get_contents($retained->path()), true, flags: JSON_THROW_ON_ERROR);

    expect($retained->metadata())->toMatchArray([
        'backend' => 'docker',
        'host' => 'sidecar1',
        'slot' => 1,
        'path' => $retained->path(),
        'owner' => 'manual-debug-session',
        'retained' => true,
    ])->and($payload)->toMatchArray([
        'backend' => 'docker',
        'host' => 'sidecar1',
        'slot' => 1,
        'owner' => 'manual-debug-session',
        'pid' => null,
        'retained' => true,
    ])->and($payload['retained_at'])->toBeInt();

    expect($pool->snapshot('docker', ['sidecar1' => 1]))->toMatchArray([
        ['host' => 'sidecar1', 'slot' => 1, 'leased' => true],
    ]);

    $retained->release();

    expect($pool->snapshot('docker', ['sidecar1' => 1]))->toMatchArray([
        ['host' => 'sidecar1', 'slot' => 1, 'leased' => false],
    ]);
});

it('does not reclaim retained leases owned by dead processes', function (): void {
    $pool = new E2EResourceLeasePool($this->leaseDirectory, waitSeconds: 0, staleSeconds: 0);
    $retained = $pool->acquire('incus', ['beast' => 1])->retain('manual-incus-session');

    $payload = json_decode((string) file_get_contents($retained->path()), true, flags: JSON_THROW_ON_ERROR);
    $payload['pid'] = 999_999_999;
    file_put_contents($retained->path(), json_encode($payload, JSON_THROW_ON_ERROR));

    expect($pool->snapshot('incus', ['beast' => 1]))->toMatchArray([
        ['host' => 'beast', 'slot' => 1, 'leased' => true],
    ]);

    $retained->release();
});
