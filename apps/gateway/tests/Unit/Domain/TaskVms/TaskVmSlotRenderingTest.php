<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmSlot;
use App\Domain\TaskVms\TaskVmSlotBoot;
use App\Domain\TaskVms\TaskVmSlotIdentity;
use App\Infrastructure\TaskVms\TaskVmVolumes;

function slotTestHost(): TaskVmHost
{
    return new TaskVmHost(nodeId: 7, project: 'orbit-tasks', network: 'orbittask0', cidr: '10.251.77.0/24', maxVms: 5, cpus: 4, memory: '8GiB', disk: '40GiB');
}

it('names a slot after its host and keeps the volume and seed clone names derived from it', function (): void {
    expect(TaskVmSlot::name('beast', 1))->toBe('beast-tvm-1');
    expect(TaskVmSlot::name('Shark_2', 5))->toBe('shark-2-tvm-5');
    expect(TaskVmSlot::identityVolume('beast-tvm-1'))->toBe('beast-tvm-1-identity');
    expect(TaskVmSlot::seedClone('beast-tvm-1'))->toBe('beast-tvm-1-topology-seed');
    expect(TaskVmSlot::seedSource('c1c2cf4051fb0123'))->toBe('orbit-topology-seed-c1c2cf4051fb');
})->group('slots');

it('refuses a slot outside 1 to 64 and a name that is too long', function (int $slot, string $host): void {
    expect(fn () => TaskVmSlot::name($host, $slot))->toThrow(ResourceOperationException::class);
})->with([[0, 'beast'], [65, 'beast'], [1, str_repeat('h', 60)], [1, '---']]);

it('gives each slot the same static address and MAC every time, from the end of the bridge', function (): void {
    expect(TaskVmSlot::bridgeAddress(slotTestHost(), 1))->toBe('10.251.77.254');
    expect(TaskVmSlot::bridgeAddress(slotTestHost(), 5))->toBe('10.251.77.250');
    expect(TaskVmSlot::bridgeMac('beast-tvm-1'))->toBe(TaskVmSlot::bridgeMac('beast-tvm-1'))
        ->toMatch('/\A10:66:6a(?::[0-9a-f]{2}){3}\z/D')
        ->not->toBe(TaskVmSlot::bridgeMac('beast-tvm-2'));
})->group('slots');

it('renders the identity tree without a key or a secret, and the revision follows the rendered text', function (): void {
    $peer = ['config' => "[Interface]\nAddress = 10.44.0.130/24\n", 'dns_marker' => "orbit\n10.44.0.1\n"];
    $agent = ['configuration' => "gateway_url = \"https://gateway.orbit\"\ngateway_address = \"10.44.0.2\"\n", 'certificate' => "-----BEGIN CERTIFICATE-----\nroot\n-----END CERTIFICATE-----\n"];
    $files = TaskVmSlotIdentity::files($peer, 'http://10.44.0.3:8317/', ['gpt-6', 'sonnet-5'], $agent, '10.44.0.130', 3774);

    expect(array_keys($files))->toBe(['etc/wireguard/orbit.conf', 'etc/wireguard/orbit.dns-link', 'etc/orbit/agent/config.toml', 'etc/orbit/agent/ca.pem', 'etc/orbit/pi.env',
        'etc/cloud/cloud.cfg.d/99-orbit-slot.cfg', 'home/orbit/.pi/agent/models.json']);
    expect($files['etc/orbit/agent/config.toml'])->toBe($agent['configuration']);
    expect($files['etc/orbit/agent/ca.pem'])->toBe($agent['certificate']);
    expect($files['etc/orbit/pi.env'])->toBe("PI_HOST=10.44.0.130\nPI_PORT=3774\n");
    expect($files['etc/cloud/cloud.cfg.d/99-orbit-slot.cfg'])->toBe("ssh_deletekeys: false\n");
    $models = json_decode($files['home/orbit/.pi/agent/models.json'], true, flags: JSON_THROW_ON_ERROR);
    expect($models['providers']['orbit-task-vm']['apiKey'])->toBe(TaskVmSlotIdentity::ModelKeyMark);
    expect($models['providers']['orbit-task-vm']['baseUrl'])->toBe('http://10.44.0.3:8317/v1');
    expect(array_column($models['providers']['orbit-task-vm']['models'], 'id'))->toBe(['gpt-6', 'sonnet-5']);

    $revision = TaskVmSlotIdentity::revision('beast-tvm-1', $files);
    expect($revision)->toMatch('/\A[a-f0-9]{64}\z/D');
    expect(TaskVmSlotIdentity::revision('beast-tvm-1', array_reverse($files, true)))->toBe($revision);
    expect(TaskVmSlotIdentity::revision('beast-tvm-2', $files))->not->toBe($revision);
    expect(TaskVmSlotIdentity::revision('beast-tvm-1', [...$files, 'etc/wireguard/orbit.dns-link' => "orbit\n10.44.0.9\n"]))->not->toBe($revision);
    // A new Gateway address, CA or Pi port changes the revision, so a claim finds a stale volume.
    expect(TaskVmSlotIdentity::revision('beast-tvm-1', [...$files, 'etc/orbit/pi.env' => "PI_HOST=10.44.0.130\nPI_PORT=3775\n"]))->not->toBe($revision);
    expect(TaskVmSlotIdentity::revision('beast-tvm-1', [...$files, 'etc/orbit/agent/ca.pem' => 'other']))->not->toBe($revision);
})->group('slots');

it('boots the slot VM with the read-only identity disk named identity, the seed clone and the static NIC', function (): void {
    $config = json_decode(TaskVmSlotBoot::config(slotTestHost(), 'beast-tvm-1', 'ssh-ed25519 AAAA', '10.251.77.254', '10:66:6a:01:02:03'), true, flags: JSON_THROW_ON_ERROR);

    expect($config['devices']['identity'])->toBe(['type' => 'disk', 'pool' => 'orbit-tasks', 'source' => 'beast-tvm-1-identity', 'path' => '/opt/orbit-slot', 'readonly' => 'true']);
    expect($config['devices']['topology-seed']['source'])->toBe('beast-tvm-1-topology-seed');
    expect($config['devices']['eth0'])->toMatchArray(['network' => 'orbittask0', 'ipv4.address' => '10.251.77.254', 'hwaddr' => '10:66:6a:01:02:03']);
    expect($config['config']['limits.cpu'])->toBe('4');
    expect($config['config']['cloud-init.user-data'])->toContain('ssh-ed25519 AAAA');
})->group('slots');

it('owns the seed clone and the identity volume of a slot, as well as the clone of a task VM', function (): void {
    expect(TaskVmVolumes::owns('beast-tvm-1-topology-seed', []))->toBeTrue();
    expect(TaskVmVolumes::owns('beast-tvm-1-identity', []))->toBeTrue();
    expect(TaskVmVolumes::owns('tvm-7-topology-seed', []))->toBeTrue();
    expect(TaskVmVolumes::owns('beast-identity', []))->toBeFalse();
    expect(TaskVmVolumes::owns('backups-tvm-x-identity', []))->toBeFalse();
})->group('slots');
