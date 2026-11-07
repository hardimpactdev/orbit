<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\Nodes\GatewayRuntimeFiles;
use App\Domain\Nodes\NativeNodeRoleRuntimeInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
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
        'retired_settings' => ['ORBIT_TASK_VMS_UPCLOUD_TOKEN_FILE', 'ORBIT_TASK_VMS_UPCLOUD_MAX_VMS', 'ORBIT_TASK_VMS_UPCLOUD_PLAN', 'ORBIT_TASK_VMS_UPCLOUD_DISK_GB']])
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
        ->and($units['orbit-gateway-schedule.service']['unit'])->toContain('"schedule:work"', "RuntimeDirectory=orbit\n")->not->toContain('KillMode');

    foreach (['horizon', 'schedule-work'] as $name) {
        Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => $name, 'runtime' => 'systemd', 'runtime_config' => ['command' => ['true']]]);
    }

    expect($names())->toBe(['orbit-agent-view.service', 'orbit-runtime-hibernator.timer']);
});
