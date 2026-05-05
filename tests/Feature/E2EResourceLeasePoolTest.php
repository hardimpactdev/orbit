<?php

declare(strict_types=1);

use App\E2E\Support\E2EResourceLeasePool;

beforeEach(function (): void {
    $this->leaseDirectory = storage_path('framework/e2e/test-leases-'.bin2hex(random_bytes(4)));

    mkdir($this->leaseDirectory, 0777, true);
});

afterEach(function (): void {
    if (is_dir($this->leaseDirectory)) {
        exec('rm -rf '.escapeshellarg($this->leaseDirectory));
    }
});

it('acquires and releases a named resource slot', function (): void {
    $pool = new E2EResourceLeasePool($this->leaseDirectory, waitSeconds: 1, staleSeconds: 60);

    $lease = $pool->acquire('docker', ['sidecar1' => 2]);

    expect($lease->backend())->toBe('docker')
        ->and($lease->host())->toBe('sidecar1')
        ->and($lease->slot())->toBe(1);

    expect($pool->snapshot('docker', ['sidecar1' => 2]))->toMatchArray([
        ['host' => 'sidecar1', 'slot' => 1, 'leased' => true],
        ['host' => 'sidecar1', 'slot' => 2, 'leased' => false],
    ]);

    $lease->release();

    expect($pool->snapshot('docker', ['sidecar1' => 2]))->toMatchArray([
        ['host' => 'sidecar1', 'slot' => 1, 'leased' => false],
        ['host' => 'sidecar1', 'slot' => 2, 'leased' => false],
    ]);
});

it('allocates different slots while prior leases are held', function (): void {
    $pool = new E2EResourceLeasePool($this->leaseDirectory, waitSeconds: 1, staleSeconds: 60);

    $first = $pool->acquire('docker', ['sidecar1' => 2]);
    $second = $pool->acquire('docker', ['sidecar1' => 2]);

    expect($first->slot())->toBe(1)
        ->and($second->slot())->toBe(2);

    $first->release();
    $second->release();
});

it('waits for unavailable slots and then fails with a useful message', function (): void {
    $pool = new E2EResourceLeasePool($this->leaseDirectory, waitSeconds: 0, staleSeconds: 60);
    $held = $pool->acquire('incus', ['sidecar1' => 1]);

    expect(fn () => $pool->acquire('incus', ['sidecar1' => 1]))
        ->toThrow(RuntimeException::class, 'No incus E2E slot became available');

    $held->release();
});

it('reclaims stale leases before acquiring', function (): void {
    $pool = new E2EResourceLeasePool($this->leaseDirectory, waitSeconds: 1, staleSeconds: 0);
    $stale = $pool->acquire('docker', ['beast' => 1]);

    $fresh = $pool->acquire('docker', ['beast' => 1]);

    expect($stale->slot())->toBe(1)
        ->and($fresh->slot())->toBe(1);

    $fresh->release();
});

it('uses an explicit lease directory from the environment', function (): void {
    withE2EEnvironment([], [
        'ORBIT_E2E_LEASE_DIRECTORY' => $this->leaseDirectory,
        'ORBIT_E2E_SLOT_WAIT_SECONDS' => '3',
        'ORBIT_E2E_SLOT_STALE_SECONDS' => '4',
    ], function (): void {
        $pool = E2EResourceLeasePool::fromEnvironment();

        expect($pool->directory())->toBe($this->leaseDirectory);
    });
});

it('uses the main checkout lease directory for git worktrees', function (): void {
    $main = storage_path('framework/e2e/fake-main-'.bin2hex(random_bytes(4)));
    $worktree = storage_path('framework/e2e/fake-worktree-'.bin2hex(random_bytes(4)));
    $gitWorktreeDirectory = "{$main}/.git/worktrees/feature";

    mkdir($gitWorktreeDirectory, 0777, true);
    mkdir($worktree, 0777, true);
    file_put_contents("{$worktree}/.git", "gitdir: {$gitWorktreeDirectory}\n");

    try {
        expect(E2EResourceLeasePool::defaultDirectoryFor($worktree))
            ->toBe("{$main}/storage/framework/e2e/leases");
    } finally {
        exec('rm -rf '.escapeshellarg($main));
        exec('rm -rf '.escapeshellarg($worktree));
    }
});
