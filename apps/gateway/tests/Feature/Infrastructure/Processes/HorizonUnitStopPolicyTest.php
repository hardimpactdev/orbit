<?php

declare(strict_types=1);

use App\Domain\Processes\ProcessTarget;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Gateway\GatewayHorizon;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function stopPolicyProcess(string $name, array $command): array
{
    $node = Node::withoutEvents(static fn (): Node => Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2',
        'user' => 'orbit', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64']));
    $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => $name, 'runtime' => 'systemd',
        'working_directory' => '/home/orbit/orbit/apps/gateway', 'restart_policy' => 'always', 'runtime_config' => ['command' => $command]]);

    return [$process, new ProcessTarget($node, 'orbit', '/home/orbit/orbit/apps/gateway')];
}

it('makes the Horizon unit wait for running jobs when it stops', function (): void {
    config(['horizon.defaults' => ['supervisor-1' => ['timeout' => 1700], 'supervisor-2' => ['timeout' => 300]]]);
    [$process, $target] = stopPolicyProcess('horizon', ['/usr/bin/php8.5', 'artisan', 'horizon']);

    $unit = (new SystemdProcessRenderer)->render($process, $target);

    expect(GatewayHorizon::drainSeconds())->toBe(1730)
        ->and($unit)->toContain("KillMode=mixed\nKillSignal=SIGTERM\nSendSIGKILL=no\nTimeoutStopSec=1730\n");
});

it('leaves the stop policy of every other Process alone', function (): void {
    [$process, $target] = stopPolicyProcess('schedule-work', ['/usr/bin/php8.5', 'artisan', 'schedule:work']);

    $unit = (new SystemdProcessRenderer)->render($process, $target);

    expect($unit)->not->toContain('KillMode')->not->toContain('SendSIGKILL')->not->toContain('TimeoutStopSec');
});

it('leaves the stop policy to the Gateway: an Instance Horizon unit does not get it', function (): void {
    [$process, $target] = stopPolicyProcess('horizon', ['/usr/bin/php8.5', 'artisan', 'horizon']);

    $unit = (new SystemdProcessRenderer)->render($process, new ProcessTarget($target->node, 'orbit', '/home/orbit/apps/site', instance: new Instance));

    expect($unit)->not->toContain('KillMode')->not->toContain('TimeoutStopSec');
});
