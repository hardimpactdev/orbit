<?php

declare(strict_types=1);

use App\Actions\Nodes\UpdateNodeAction;
use App\Data\Nodes\NodeStorageAppsData;
use App\Data\Nodes\NodeTaskVmsData;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\NodeSettingsPatch;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Firewall\NodeFirewallDesiredRules;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampConnection;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

it('preserves a loop pool size through typed node settings and host configuration', function (): void {
    $value = ['cidr' => '10.252.10.0/24', 'task_places' => 3, 'ci_places' => 2, 'pool_size' => '512GiB', 'disk' => '60GiB'];
    $settings = NodeTaskVmsData::validated($value, Ipv4Subnet::from('10.44.0.0/24'));
    $host = TaskVmHost::fromConfig([...$settings->toArray(), 'node_id' => 12], 0, Ipv4Subnet::from('10.44.0.0/24'), []);

    expect($settings->toArray())->toMatchArray($value)
        ->and($host->poolSize)->toBe('512GiB')
        ->and($host->ciPlaces)->toBe(2)
        ->and($host->zfsDataset)->toBeNull()
        ->and($host->taskPlaces)->toBe(3);
});

it('stores an explicit or default loop pool size through the node update API', function (?string $size): void {
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'loop-host', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.10', 'public_ssh_host' => '10.44.0.10', 'user' => 'orbit',
    ]));
    $node->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $settings = ['cidr' => '10.252.10.0/24', 'task_places' => 3, 'ci_places' => 2, ...($size === null ? [] : ['pool_size' => $size])];

    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])
        ->patchJson("/api/v1/nodes/{$node->id}", ['task_vms' => $settings])
        ->assertOk()->assertJsonPath('data.settings.task_vms.pool_size', $size ?? '640GiB')
        ->assertJsonPath('data.settings.task_vms.ci_places', 2);

    expect($node->refresh()->settings['task_vms']['pool_size'])->toBe($size ?? '640GiB');
})->with(['explicit' => ['512GiB'], 'default' => [null]]);

it('keeps native dataset intent and defaults the size of a future loop pool', function (): void {
    $settings = NodeTaskVmsData::validated(['cidr' => '10.252.10.0/24', 'task_places' => 3, 'ci_places' => 2, 'zfs_dataset' => 'data/orbit-tasks'], Ipv4Subnet::from('10.44.0.0/24'));

    expect($settings->pool_size)->toBe('640GiB')
        ->and($settings->zfs_dataset)->toBe('data/orbit-tasks');
});

it('refuses an invalid loop pool size before it can become host intent', function (mixed $size): void {
    expect(fn () => NodeTaskVmsData::validated(['cidr' => '10.252.10.0/24', 'task_places' => 3, 'ci_places' => 2, 'pool_size' => $size], Ipv4Subnet::from('10.44.0.0/24')))
        ->toThrow(TaskVmException::class, 'pool_size');
})->with(['0GiB', '640GB', '640GiB source=/dev/sda', 640, null]);

it('validates separate place counts and rejects retired capacity fields', function (): void {
    $value = ['cidr' => '10.252.10.0/24', 'task_places' => 0, 'ci_places' => 2];
    expect(NodeTaskVmsData::validated($value, Ipv4Subnet::from('10.44.0.0/24'))->toArray())->toMatchArray($value);
    foreach (['task_places' => -1, 'ci_places' => 65, 'task_max_vms' => 2, 'max_vms' => 12] as $key => $invalid) {
        expect(fn () => NodeTaskVmsData::validated([...$value, $key => $invalid], Ipv4Subnet::from('10.44.0.0/24')))->toThrow(TaskVmException::class);
    }
});

it('reads the native withdrawal result before saving removal or a bridge change, and retains settings after failure', function (?array $replacement, string $terminal, bool $apps = false, ?string $failedPhase = null): void {
    Http::preventStrayRequests();
    SwampNodeFixture::ensure();
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'retiring-host', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.10', 'public_ssh_host' => '10.44.0.10', 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
        'settings' => ['task_vms' => ['cidr' => '10.252.10.0/24', 'task_places' => 6, 'ci_places' => 2]],
    ]));
    $previous = $node->settings;
    $patch = new NodeSettingsPatch($apps, $apps ? NodeStorageAppsData::from(['path' => '/home/orbit/task-apps']) : null, true, $replacement === null ? null : NodeTaskVmsData::from($replacement));

    // Keep the after-commit Swamp dispatch outside this admission/projection test.
    DB::beginTransaction();
    $operation = app(UpdateNodeAction::class)->execute($node, 'changed-host', null, $patch);

    expect($operation)->toBeInstanceOf(Operation::class);
    expect($operation->input['task_host_input_comments'])->toBe(['orbit:task-vms-dhcp', 'orbit:task-vms-dns-tcp', 'orbit:task-vms-dns-udp']);
    expect($operation->input['root'])->toBe($apps ? '/home/orbit/task-apps' : null);
    expect($node->refresh()->settings)->toBe($previous)
        ->and($node->name)->toBe('changed-host');
    $operation->update(['status' => 'running', 'state' => ['swamp_run_id' => 'fixture-task-host-withdrawal']]);
    $definition = app(DirectOperation::class)->definition('node.update');
    $history = [
        'id' => 'fixture-task-host-withdrawal', 'workflowName' => $definition['workflow'], 'status' => $terminal,
        'startedAt' => now()->toIso8601String(), 'completedAt' => now()->toIso8601String(),
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $node->id, 'kind' => 'node.update'],
        'jobs' => [['name' => 'settings', 'steps' => [
            ['name' => 'Withdraw task host input', 'status' => $failedPhase === 'Withdraw task host input' || ($terminal === 'failed' && $failedPhase === null) ? 'failed' : 'succeeded',
                ...($failedPhase === 'Withdraw task host input' ? ['error' => 'withdrawal failure'] : [])],
            ['name' => 'Prepare the apps folder', 'status' => $failedPhase === 'Prepare the apps folder' ? 'failed' : ($apps ? 'succeeded' : 'skipped'),
                ...($failedPhase === 'Prepare the apps folder' ? ['error' => 'folder failure'] : [])],
            ['name' => 'Cleanup', 'status' => $failedPhase === 'Cleanup' ? 'failed' : ($terminal === 'interrupted' ? 'skipped' : 'succeeded'),
                ...($failedPhase === 'Cleanup' ? ['error' => 'cleanup failure'] : [])],
        ]]],
    ];
    app(SwampConnection::class)->release();
    $frame = null;
    $socket = $this->mock(WebSocketClient::class);
    $socket->shouldReceive('connect', 'close');
    $socket->shouldReceive('isConnected')->andReturnTrue();
    $socket->shouldReceive('send')->once()->withArgs(function (array $message) use (&$frame): bool {
        $frame = $message;

        return $message['type'] === 'workflow.history.get';
    });
    $socket->shouldReceive('receive')->once()->andReturnUsing(function () use (&$frame, $history): array {
        return [['id' => $frame['id'], 'type' => $frame['type'], 'payload' => ['data' => $history]]];
    });
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.read_token' => 'read-token']);
    app(SwampCommandStep::class)->projectTerminal($operation, ['id' => $history['id'], 'status' => $terminal], $definition['terminal_result']);

    expect($operation->refresh()->status)->toBe(OperationStatus::from($terminal));

    expect($node->refresh()->name)->toBe('changed-host');
    if ($terminal !== 'succeeded') {
        expect($node->refresh()->settings)->toBe($previous);
        if ($failedPhase !== null) {
            expect($operation->failed_step)->toBe($failedPhase);
        }
        expect($operation->result['cleanup_complete'])->toBe($failedPhase !== 'Cleanup' && $terminal !== 'interrupted');
        if ($failedPhase === 'Prepare the apps folder') {
            expect($operation->error_code)->toBe('node.settings_root_failed');
        }
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
    app(SwampConnection::class)->release();
    DB::rollBack();
})->with([
    'removed' => [null, 'succeeded'],
    'withdrawal failed' => [null, 'failed'],
    'bridge changed' => [['cidr' => '10.252.42.0/24', 'network' => 'proofbr0', 'task_places' => 6, 'ci_places' => 2], 'succeeded'],
    'with apps preparation' => [null, 'succeeded', true],
    'folder failure retains the name and old settings' => [null, 'failed', true, 'Prepare the apps folder'],
    'cleanup failure retains deferred settings' => [null, 'failed', true, 'Cleanup'],
    'interrupted run reports incomplete cleanup' => [null, 'interrupted', true],
]);
