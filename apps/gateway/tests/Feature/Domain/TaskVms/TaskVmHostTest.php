<?php

declare(strict_types=1);

use App\Data\Nodes\NodeTaskVmsData;
use App\Domain\Nodes\RoleName;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('preserves a loop pool size through typed node settings and host configuration', function (): void {
    $value = ['cidr' => '10.252.10.0/24', 'max_vms' => 3, 'ci_only' => true, 'pool_size' => '512GiB', 'disk' => '60GiB'];
    $settings = NodeTaskVmsData::validated($value, Ipv4Subnet::from('10.44.0.0/24'));
    $host = TaskVmHost::fromConfig([...$settings->toArray(), 'node_id' => 12], 0, Ipv4Subnet::from('10.44.0.0/24'), []);

    expect($settings->toArray())->toMatchArray($value)
        ->and($host->poolSize)->toBe('512GiB')
        ->and($host->ciOnly)->toBeTrue()
        ->and($host->zfsDataset)->toBeNull()
        ->and($host->maxVms)->toBe(3);
});

it('stores an explicit or default loop pool size through the node update API', function (?string $size): void {
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'loop-host', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.10', 'public_ssh_host' => '10.44.0.10', 'user' => 'orbit',
    ]));
    $node->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $settings = ['cidr' => '10.252.10.0/24', 'max_vms' => 3, 'ci_only' => true, ...($size === null ? [] : ['pool_size' => $size])];

    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])
        ->patchJson("/api/v1/nodes/{$node->id}", ['task_vms' => $settings])
        ->assertOk()->assertJsonPath('data.settings.task_vms.pool_size', $size ?? '640GiB')
        ->assertJsonPath('data.settings.task_vms.ci_only', true);

    expect($node->refresh()->settings['task_vms']['pool_size'])->toBe($size ?? '640GiB');
})->with(['explicit' => ['512GiB'], 'default' => [null]]);

it('keeps native dataset intent and defaults the size of a future loop pool', function (): void {
    $settings = NodeTaskVmsData::validated(['cidr' => '10.252.10.0/24', 'max_vms' => 3, 'zfs_dataset' => 'data/orbit-tasks'], Ipv4Subnet::from('10.44.0.0/24'));

    expect($settings->pool_size)->toBe('640GiB')
        ->and($settings->zfs_dataset)->toBe('data/orbit-tasks');
});

it('refuses an invalid loop pool size before it can become host intent', function (mixed $size): void {
    expect(fn () => NodeTaskVmsData::validated(['cidr' => '10.252.10.0/24', 'max_vms' => 3, 'pool_size' => $size], Ipv4Subnet::from('10.44.0.0/24')))
        ->toThrow(TaskVmException::class, 'pool_size');
})->with(['0GiB', '640GB', '640GiB source=/dev/sda', 640, null]);

it('stores and clears a task VM count limit through node update', function (?int $limit): void {
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'task-host', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.10', 'public_ssh_host' => '10.44.0.10', 'user' => 'orbit',
        'settings' => ['task_vms' => ['cidr' => '10.252.10.0/24', 'max_vms' => 12, 'task_max_vms' => 1]],
    ]));
    $node->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $value = ['cidr' => '10.252.10.0/24', 'max_vms' => 12, 'task_max_vms' => $limit];
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])
        ->patchJson("/api/v1/nodes/{$node->id}", ['task_vms' => $value])->assertOk()
        ->assertJsonPath('data.settings.task_vms.task_max_vms', $limit);
    $settings = $node->refresh()->settings['task_vms'];
    expect($settings['task_max_vms'])->toBe($limit);
    expect(TaskVmHost::fromConfig([...$settings, 'node_id' => $node->id], 0, Ipv4Subnet::from('10.44.0.0/24'), [])->taskMaxVms)->toBe($limit);
})->with([0, 2, 64, null]);

it('rejects invalid task VM count limits', function (mixed $limit): void {
    expect(fn () => NodeTaskVmsData::validated(['cidr' => '10.252.10.0/24', 'max_vms' => 12, 'task_max_vms' => $limit], Ipv4Subnet::from('10.44.0.0/24')))
        ->toThrow(TaskVmException::class, 'task_max_vms');
})->with([-1, 65, '2', 2.5, true]);

it('returns 422 for a string task limit and preserves the stored host settings', function (): void {
    $value = ['cidr' => '10.252.10.0/24', 'max_vms' => 12, 'task_max_vms' => 2];
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'task-host', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.10', 'public_ssh_host' => '10.44.0.10', 'user' => 'orbit', 'settings' => ['task_vms' => $value],
    ]));
    $node->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])
        ->patchJson("/api/v1/nodes/{$node->id}", ['task_vms' => [...$value, 'task_max_vms' => '2']])->assertUnprocessable()
        ->assertJsonPath('error.code', 'node.settings_invalid');
    expect($node->refresh()->settings['task_vms'])->toBe($value);
});
