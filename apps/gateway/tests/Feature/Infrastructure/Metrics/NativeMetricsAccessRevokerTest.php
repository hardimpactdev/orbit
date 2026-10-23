<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\NativeMetricsAccessRevoker;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\Node;
use Symfony\Component\Process\Process;
use Tests\Support\FakeAgentCommandRunner;

it('reloads Caddy whenever a Metrics route may remain published', function (
    LifecycleStatus $roleStatus,
    LifecycleStatus $nodeStatus,
): void {
    $gateway = metricsAccessRevokerGateway();
    $metrics = metricsAccessRevokerNode('metrics', $nodeStatus);
    $metrics->roles()->create([
        'role' => RoleName::Metrics,
        'status' => $roleStatus,
    ]);
    $agents = new FakeAgentCommandRunner()->answer('caddy.reload', []);

    new NativeMetricsAccessRevoker($agents)->revoke();

    expect($agents->calls)->toBe([['node' => $gateway->name, 'command' => 'caddy.reload', 'input' => []]]);
})->with([
    'active assignment' => [LifecycleStatus::Active, LifecycleStatus::Active],
    'provisioning assignment' => [LifecycleStatus::Provisioning, LifecycleStatus::Active],
    'failed assignment' => [LifecycleStatus::Failed, LifecycleStatus::Active],
    'removing assignment' => [LifecycleStatus::Removing, LifecycleStatus::Active],
    'inactive Metrics node' => [LifecycleStatus::Active, LifecycleStatus::Failed],
]);

it('does not touch Caddy without an active Metrics assignment', function (): void {
    $agents = new FakeAgentCommandRunner()->answer('caddy.reload', []);

    new NativeMetricsAccessRevoker($agents)->revoke();

    expect($agents->calls)->toBeEmpty();
});

it('fails closed when the stream-closing reload fails', function (): void {
    metricsAccessRevokerGateway();
    $metrics = metricsAccessRevokerNode('metrics');
    $metrics->roles()->create([
        'role' => RoleName::Metrics,
        'status' => LifecycleStatus::Active,
    ]);
    $agents = new FakeAgentCommandRunner()->refuse('caddy.reload', 'caddy.reload_failed');

    expect(fn () => new NativeMetricsAccessRevoker($agents)->revoke())
        ->toThrow(ResourceOperationException::class, 'Metrics Caddy publication did not complete.');
});

function metricsAccessRevokerNode(
    string $name,
    LifecycleStatus $status = LifecycleStatus::Active,
): Node {
    return Node::query()->create([
        'name' => $name,
        'status' => $status,
        'platform' => 'linux',
        'public_ssh_host' => $name.'.example.test',
        'user' => 'orbit',
        'wireguard_ip' => $name === 'gateway' ? '10.44.0.1' : '10.44.0.3',
    ]);
}

function metricsAccessRevokerGateway(): Node
{
    $gateway = metricsAccessRevokerNode('gateway');
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);

    return $gateway;
}

arch('Metrics access revocation runs Node code only through the agent runner')
    ->expect(NativeMetricsAccessRevoker::class)
    ->not->toUse([
        SshExecutor::class,
        RemoteCommand::class,
        ProcessRunner::class,
        ProcessInvocation::class,
        Process::class,
    ]);
