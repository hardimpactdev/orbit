<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Metrics\NativeMetricsRoleManager;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;

it('refuses removal without a Metrics assignment', function (): void {
    expect(fn () => app(NativeMetricsRoleManager::class)->remove(true, false))
        ->toThrow(RoleAssignmentException::class, 'Metrics is not assigned.');
    $this->assertDatabaseCount('operations', 0);
});

it('fails closed before removal when Metrics assignments drift', function (): void {
    metricsRoleManagerNode('metrics-a', '10.44.0.3');
    metricsRoleManagerNode('metrics-b', '10.44.0.4');

    expect(fn () => app(NativeMetricsRoleManager::class)->remove(true, false))
        ->toThrow(RoleAssignmentException::class, 'Metrics role assignment drift detected.');
    $this->assertDatabaseCount('operations', 0);
    expect(Node::query()->whereHas('roles')->count())->toBe(2);
});

it('refuses a second Metrics assignment before recording intent', function (): void {
    metricsRoleManagerNode('metrics', '10.44.0.3');
    $target = metricsRoleManagerNode('target', '10.44.0.4', false);

    expect(fn () => app(NativeMetricsRoleManager::class)->enable($target->id))
        ->toThrow(RoleAssignmentException::class, 'The metrics role is already assigned');
    $this->assertDatabaseCount('operations', 0);
    expect($target->roles()->count())->toBe(0);
});

it('rejects an unsupported platform before recording role intent', function (): void {
    $node = metricsRoleManagerNode('mac', '10.44.0.3', false);
    $node->update(['platform' => 'macos']);

    expect(fn () => app(NativeMetricsRoleManager::class)->enable($node->id))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('metrics.platform_unsupported'));
    $this->assertDatabaseCount('operations', 0);
    expect($node->roles()->count())->toBe(0);
});

it('refuses an unavailable native role kind without legacy effects or intent changes', function (bool $remove): void {
    $node = metricsRoleManagerNode('metrics', '10.44.0.3', $remove);
    $agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);
    $reflection = new ReflectionClass(DirectOperation::class);
    $direct = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('definitions')->setValue($direct, []);
    app()->instance(DirectOperation::class, $direct);
    $before = $node->roles()->get()->toArray();

    expect(fn () => $remove
        ? app(NativeMetricsRoleManager::class)->remove(true, true)
        : app(NativeMetricsRoleManager::class)->enable($node->id))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('operation.kind_unavailable'));

    $this->assertDatabaseCount('operations', 0);
    expect($node->roles()->get()->toArray())->toBe($before);
    expect($agents->calls)->toBe([]);
})->with(['enable' => false, 'remove' => true]);

function metricsRoleManagerNode(string $name, string $address, bool $assigned = true): Node
{
    $node = Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => str_replace('10.44', '192.0.2', $address),
        'wireguard_ip' => $address,
        'ssh_host_fingerprint' => 'SHA256:managed',
    ]);
    if ($assigned) {
        $node->roles()->create(['role' => RoleName::Metrics, 'status' => LifecycleStatus::Active]);
    }

    return $node;
}
