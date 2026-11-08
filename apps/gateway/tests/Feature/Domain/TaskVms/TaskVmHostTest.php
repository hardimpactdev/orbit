<?php

declare(strict_types=1);

use App\Data\Nodes\NodeTaskVmsData;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\WireGuard\Ipv4Subnet;

it('preserves a loop pool size through typed node settings and host configuration', function (): void {
    $value = ['cidr' => '10.252.10.0/24', 'max_vms' => 3, 'pool_size' => '640GiB', 'disk' => '60GiB'];
    $settings = NodeTaskVmsData::validated($value, Ipv4Subnet::from('10.44.0.0/24'));
    $host = TaskVmHost::fromConfig([...$settings->toArray(), 'node_id' => 12], 0, Ipv4Subnet::from('10.44.0.0/24'), []);

    expect($settings->toArray())->toMatchArray($value)
        ->and($host->poolSize)->toBe('640GiB')
        ->and($host->zfsDataset)->toBeNull()
        ->and($host->maxVms)->toBe(3);
});

it('keeps native dataset intent and defaults the size of a future loop pool', function (): void {
    $settings = NodeTaskVmsData::validated(['cidr' => '10.252.10.0/24', 'max_vms' => 3, 'zfs_dataset' => 'data/orbit-tasks'], Ipv4Subnet::from('10.44.0.0/24'));

    expect($settings->pool_size)->toBe('640GiB')
        ->and($settings->zfs_dataset)->toBe('data/orbit-tasks');
});

it('refuses an invalid loop pool size before it can become host intent', function (mixed $size): void {
    expect(fn () => NodeTaskVmsData::validated(['cidr' => '10.252.10.0/24', 'max_vms' => 3, 'pool_size' => $size], Ipv4Subnet::from('10.44.0.0/24')))
        ->toThrow(TaskVmException::class, 'pool_size');
})->with(['0GiB', '640GB', '640GiB source=/dev/sda', 640, null]);
