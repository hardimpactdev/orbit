<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\Nodes\GatewayRuntimeFiles;
use App\Domain\Nodes\NativeNodeRoleRemovalInput;
use App\Domain\Nodes\NativeNodeRoleRuntimeInput;
use App\Domain\Nodes\NodeRoleDirectInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Nodes\NodePrivateDnsListenerFootprint;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('converges the Gateway runtime with the gateway role and retires the release unit and the start hook', function (): void {
    config(['orbit.gateway_checkout' => '/home/orbit/orbit/apps/gateway', 'orbit.home' => '/home/orbit/.orbit', 'orbit.hibernation.sweep_seconds' => 600]);
    $node = Node::withoutEvents(static fn (): Node => Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2',
        'user' => 'orbit', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64']));
    $role = $node->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $operation = Operation::query()->create(['kind' => 'node.role_converge', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
        'status' => 'running', 'input' => [], 'triggered_by' => 'test']);

    $runtime = app(NativeNodeRoleRuntimeInput::class)->capture($node, $role, ['gateway_dns' => null, 'runtime' => null], $operation)['intent'];
    $files = collect($runtime['files'])->keyBy('path');

    expect($runtime['gateway'])->toBe(['pool' => GatewayRuntimeFiles::Pool, 'release_units' => GatewayRuntimeFiles::ReleaseUnits, 'account' => 'orbit',
        'environment' => GatewayReleaseLayout::fromConfig()->sharedPath().'/gateway.env',
        'retired_settings' => ['ORBIT_TASK_VMS_UPCLOUD_TOKEN_FILE', 'ORBIT_TASK_VMS_UPCLOUD_MAX_VMS', 'ORBIT_TASK_VMS_UPCLOUD_PLAN', 'ORBIT_TASK_VMS_UPCLOUD_DISK_GB'],
        'tmpfiles' => '/etc/tmpfiles.d/orbit-gateway.conf'])
        ->and($files['/etc/tmpfiles.d/orbit-gateway.conf'])->toMatchArray(['ensure' => 'present', 'owner' => 'root', 'group' => 'root', 'mode' => '0644'])
        ->and($files['/etc/tmpfiles.d/orbit-gateway.conf']['content'])->toEndWith("\nd /run/orbit 0755 orbit orbit -\n")
        ->and($files[GatewayRuntimeFiles::Pool]['content'])->toContain("[orbit-gateway]\n", "chdir = /home/orbit/orbit/apps/gateway\n", "env[ORBIT_HOME] = /home/orbit/.orbit\n")
        ->and($files['/etc/systemd/system/orbit-runtime-hibernator.service']['content'])->toContain("ExecStart=\"/usr/bin/php8.5\" \"/home/orbit/orbit/apps/gateway/artisan\" \"orbit:runtime-hibernator\"\n")
        ->and($files['/etc/systemd/journald.conf.d/orbit-retention.conf']['content'])->toContain("SystemMaxUse=256M\nSystemKeepFree=1024M\n")
        ->and($files->filter(static fn (array $file): bool => $file['ensure'] === 'absent')->keys()->all())->toBe([
            '/etc/systemd/system/orbit-gateway-release.timer', '/etc/systemd/system/orbit-gateway-release.service',
            '/etc/systemd/system/orbit-gateway-release-run@.service', '/etc/systemd/system/php8.5-fpm.service.d/orbit-document-cleanup.conf',
            '/etc/orbit/php-fpm-start-hooks'])
        ->and(array_column($runtime['directories'], 'path'))->toBe(['/etc/systemd/journald.conf.d', '/etc/systemd/system/logrotate.timer.d'])
        ->and(array_column($runtime['units'], 'name'))->toBe(['orbit-agent-view.service', 'orbit-gateway-schedule.service', 'orbit-runtime-hibernator.timer'])
        ->and($runtime['units'][2]['unit'])->toContain("OnUnitActiveSec=600s\n");
});

it('escapes configured paths in the units and the logrotate rule as the agent wrote them', function (): void {
    config(['orbit.gateway_checkout' => '/srv/orbit 50%/orbit/apps/gateway', 'orbit.home' => '/srv/Orbit "State"']);
    $runtime = app(GatewayRuntimeFiles::class)->render();
    $files = collect($runtime['files'])->keyBy('path');

    expect($runtime['units'][0]['unit'])->toContain(
        "WorkingDirectory=/srv/orbit\\x2050%%/orbit/apps/gateway\n",
        "Environment=ORBIT_HOME=/srv/Orbit\\x20\\x22State\\x22\n",
        "ExecStart=\"/usr/bin/php8.5\" \"/srv/orbit 50%%/orbit/apps/gateway/artisan\" \"orbit:agent-view\"\n",
    )->and($files['/etc/logrotate.d/orbit-gateway']['content'])->toContain("\"/srv/orbit 50%/orbit/apps/gateway/storage/logs/laravel.log\" {\n");
});

it('runs the scheduler and, with a queue, Horizon as units unless the Node has the Processes', function (): void {
    config(['orbit.gateway_checkout' => '/home/orbit/orbit/apps/gateway', 'orbit.home' => '/home/orbit/.orbit', 'orbit.hibernation.sweep_seconds' => 600]);
    $node = Node::withoutEvents(static fn (): Node => Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2',
        'user' => 'orbit', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64']));
    $names = static fn (): array => array_column(app(GatewayRuntimeFiles::class)->render($node)['units'], 'name');

    expect($names())->not->toContain('orbit-gateway-horizon.service')
        ->and($names())->toContain('orbit-gateway-schedule.service');

    config(['orbit.gateway_queue.connection' => 'gateway-queue']);
    $units = collect(app(GatewayRuntimeFiles::class)->render($node)['units'])->keyBy('name');

    expect($units['orbit-gateway-horizon.service']['unit'])->toContain("After=network-online.target valkey-server.service\n", "ExecStart=\"/usr/bin/php8.5\" \"/home/orbit/orbit/apps/gateway/artisan\" \"horizon\"\n", "Restart=always\n",
        "KillMode=mixed\nKillSignal=SIGTERM\nSendSIGKILL=no\nTimeoutStopSec=")
        ->and($units['orbit-gateway-schedule.service']['unit'])->toContain('"schedule:work"')->not->toContain('KillMode')->not->toContain('RuntimeDirectory')
        ->and($units['orbit-gateway-horizon.service']['unit'])->not->toContain('RuntimeDirectory');

    foreach (['horizon', 'schedule-work'] as $name) {
        Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => $name, 'runtime' => 'systemd', 'runtime_config' => ['command' => ['true']]]);
    }

    expect($names())->toBe(['orbit-agent-view.service', 'orbit-runtime-hibernator.timer']);
});

it('captures the shared local DNS listener and Gateway catalog before resolver routing', function (string $address, string $upstream, ?LifecycleStatus $vpnStatus): void {
    config(['orbit.gateway_checkout' => '/home/orbit/orbit/apps/gateway', 'orbit.home' => '/home/orbit/.orbit']);
    $node = Node::withoutEvents(static fn (): Node => Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => $address,
        'user' => 'orbit', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64']));
    $role = $node->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    if ($vpnStatus !== null) {
        $node->roles()->create(['role' => RoleName::Vpn, 'status' => $vpnStatus]);
    }
    $operation = Operation::query()->create(['kind' => 'node.role_converge', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
        'status' => 'running', 'input' => [], 'triggered_by' => 'test']);

    $node->roles()->create(['role' => RoleName::WebSocket, 'status' => LifecycleStatus::Active]);

    $dns = app(NativeNodeRoleRuntimeInput::class)->capture($node, $role, ['gateway_dns' => ['address' => $upstream, 'domain' => 'orbit'], 'runtime' => null], $operation)['intent']['gateway_dns'];
    $listener = $dns['listener'];
    $catalog = json_decode($listener['catalog'], true, flags: JSON_THROW_ON_ERROR);

    expect($dns['unit'])->toContain("ExecStart=/usr/bin/resolvectl dns orbit {$address}\n", 'ConditionPathExists=!/etc/wireguard/orbit.dns-link');
    expect($catalog['records']['gateway.orbit'])->toBe($address);
    if ($vpnStatus === null) {
        expect($catalog['records'])->toBe(['gateway.orbit' => $address])
            ->and($catalog['suffixes'])->toBe([])
            ->and($catalog['overrides'])->toBe([]);
    } else {
        expect($catalog['records']['reverb.orbit'])->toBe($address);
    }
    expect($listener['catalog_sha256'])->toBe(hash('sha256', $listener['catalog']))
        ->and($listener['address'])->toBe($address)
        ->and($listener['socket'])->toContain("ListenDatagram={$address}:53\n", "ListenStream={$address}:53\n")
        ->and($listener['service'])->toContain('Sockets=orbit-private-dns.socket', '"--catalog=/var/lib/orbit/private-dns/catalog.json"',
            '"--upstream='.($vpnStatus !== null || $address === $upstream ? '127.0.0.55' : $upstream).':53"')
        ->and(array_column($listener['files'], 'path'))->toContain($listener['release'].'/serve.php');

    if ($vpnStatus !== null) {
        $footprint = app(NodePrivateDnsListenerFootprint::class)->input($node);
        expect($listener['service'])->toBe($footprint['service'])
            ->and($listener['socket'])->toBe($footprint['socket']);
    }
})->with([
    'production Gateway peer forwards to the fleet DNS' => ['10.44.0.2', '10.44.0.1', null],
    'stock Gateway before its first VPN publication' => ['10.45.0.1', '10.45.0.1', LifecycleStatus::Provisioning],
    'active colocated VPN keeps the same listener owner' => ['10.45.0.1', '10.45.0.1', LifecycleStatus::Active],
]);

it('withdraws the Gateway listener only when no colocated VPN needs it', function (bool $vpn): void {
    config(['swamp.ssh_public_key' => 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('k', 32)).' control']);
    $node = Node::withoutEvents(static fn (): Node => Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture',
        'user' => 'orbit', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64']));
    $role = $node->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Removing]);
    $node->roles()->create(['role' => RoleName::Swamp, 'status' => LifecycleStatus::Active]);
    if ($vpn) {
        $node->roles()->create(['role' => RoleName::Vpn, 'status' => LifecycleStatus::Active]);
    }
    $capture = app(NativeNodeRoleRemovalInput::class);
    $before = $capture->publications($node, RoleName::Gateway);
    $plan = $capture->capture($node, $role, $before);

    $units = array_column(array_column($plan['stop_units'], 'input'), 'unit');
    expect($units)->toBe($vpn ? ['orbit-gateway-dns.service'] : ['orbit-gateway-dns.service', 'orbit-private-dns.socket', 'orbit-private-dns.service']);
    expect(array_column(array_column($plan['remove_units'], 'input'), 'unit'))->toBe($units);
})->with(['Gateway-only' => [false], 'Gateway and VPN' => [true]]);

it('captures Gateway DNS without a configured DNS server or a separate VPN Node', function (): void {
    config(['swamp.ssh_public_key' => 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('k', 32)).' control']);
    $node = Node::withoutEvents(static fn (): Node => Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.45.0.1',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture',
        'user' => 'orbit', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64']));
    $role = $node->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $node->roles()->create(['role' => RoleName::Swamp, 'status' => LifecycleStatus::Active]);

    $snapshot = app(NodeRoleDirectInput::class)->capture($node, $role, false);

    expect($snapshot['gateway_dns'])->toBe(['operation' => 'converge', 'address' => '10.45.0.1', 'domain' => 'orbit']);
});
