<?php

declare(strict_types=1);

use App\Actions\Nodes\UpdateNodeAction;
use App\Data\Nodes\NodeStorageAppsData;
use App\Data\Nodes\NodeTaskVmsData;
use App\Domain\Nodes\NodeSettingsProjection;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\NodeSettingsPatch;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Http\Requests\Nodes\UpdateNodeRequest;
use App\Infrastructure\Firewall\NodeFirewallDesiredRules;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Tests\Support\SwampNodeFixture;

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
})->with([0, 2, 12, null]);

it('rejects invalid task VM count limits', function (mixed $limit): void {
    expect(fn () => NodeTaskVmsData::validated(['cidr' => '10.252.10.0/24', 'max_vms' => 12, 'task_max_vms' => $limit], Ipv4Subnet::from('10.44.0.0/24')))
        ->toThrow(TaskVmException::class, 'task_max_vms');
})->with([-1, 13, 64, 65, '2', 2.5, true]);

it('returns 422 for an invalid task limit and preserves the stored host settings', function (mixed $limit): void {
    $value = ['cidr' => '10.252.10.0/24', 'max_vms' => 12, 'task_max_vms' => 2];
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'task-host', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.10', 'public_ssh_host' => '10.44.0.10', 'user' => 'orbit', 'settings' => ['task_vms' => $value],
    ]));
    $node->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])
        ->patchJson("/api/v1/nodes/{$node->id}", ['task_vms' => [...$value, 'task_max_vms' => $limit]])->assertUnprocessable()
        ->assertJsonPath('error.code', 'node.settings_invalid')
        ->assertJsonPath('error.message', 'The task VM config is invalid: task_vms.task_max_vms must be null or an integer from 0 to max_vms.');
    expect($node->refresh()->settings['task_vms'])->toBe($value);
})->with(['string count' => ['2'], 'above host capacity' => [64]]);

it('uses the same upper bound and message in the node update request validator', function (): void {
    $request = new UpdateNodeRequest;
    $validator = Validator::make(['task_vms' => ['cidr' => '10.252.10.0/24', 'max_vms' => 12, 'task_max_vms' => 64]],
        $request->rules(), $request->messages());

    expect($validator->errors()->get('task_vms.task_max_vms'))->toBe([
        'task_vms.task_max_vms must be null or an integer from 0 to max_vms.',
    ]);
});

it('withdraws input rules before saving removal or a bridge change, and retains settings after failure', function (?array $replacement, string $terminal, bool $apps = false): void {
    Http::preventStrayRequests();
    SwampNodeFixture::ensure();
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'retiring-host', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.10', 'public_ssh_host' => '10.44.0.10', 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
        'settings' => ['task_vms' => ['cidr' => '10.252.10.0/24', 'max_vms' => 6, 'ci_only' => true]],
    ]));
    $previous = $node->settings;
    $patch = new NodeSettingsPatch($apps, $apps ? NodeStorageAppsData::from(['path' => '/home/orbit/task-apps']) : null, true, $replacement === null ? null : NodeTaskVmsData::from($replacement));

    // Keep the after-commit Swamp dispatch outside this admission/projection test.
    DB::beginTransaction();
    $operation = app(UpdateNodeAction::class)->execute($node, null, null, $patch);

    expect($operation)->toBeInstanceOf(Operation::class);
    expect($operation->input['task_host_input_comments'])->toBe(['orbit:task-vms-dhcp', 'orbit:task-vms-dns-tcp', 'orbit:task-vms-dns-udp']);
    expect($operation->input['root'])->toBe($apps ? '/home/orbit/task-apps' : null);
    expect($node->refresh()->settings)->toBe($previous);
    $operation->update(['status' => 'running', 'state' => ['swamp_run_id' => 'fixture-task-host-withdrawal']]);
    app(NodeSettingsProjection::class)->apply($operation, [
        'run_id' => 'fixture-task-host-withdrawal', 'status' => $terminal,
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'error_code' => $terminal === 'failed' ? 'node.task_host_firewall_remove_failed' : null,
        'result' => ['steps' => ['Withdraw task host input' => ['status' => $terminal],
            ...($apps ? ['Check owner and mode' => ['status' => 'succeeded', 'stdout' => 'directory orbit:orbit 755']] : [])],
            'summary' => ['failed_steps' => $terminal === 'failed' ? ['Withdraw task host input'] : []]],
    ]);

    if ($terminal === 'failed') {
        expect($node->refresh()->settings)->toBe($previous);
    } else {
        expect($node->refresh()->settings['task_vms'] ?? null)->toBe($operation->input['settings']['task_vms'] ?? null);
        $names = array_column(app(NodeFirewallDesiredRules::class)->forNode($node), 'name');
        if ($replacement === null) {
            expect($names)->not->toContain('orbit:task-vms-dhcp', 'orbit:task-vms-dns-tcp', 'orbit:task-vms-dns-udp');
        } else {
            expect($names)->toContain('orbit:task-vms-dhcp');
        }
    }
    Http::assertNothingSent();
    DB::rollBack();
})->with([
    'removed' => [null, 'succeeded'],
    'withdrawal failed' => [null, 'failed'],
    'bridge changed' => [['cidr' => '10.252.42.0/24', 'network' => 'proofbr0', 'max_vms' => 6, 'ci_only' => true], 'succeeded'],
    'with apps preparation' => [null, 'succeeded', true],
]);
