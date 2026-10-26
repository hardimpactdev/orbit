<?php

declare(strict_types=1);

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\PhpPoolDirectoryObservation;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\DevelopmentPhpFpmConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\AppDev\RemoteAppDevPhpFpmManager;
use App\Infrastructure\Doctor\NativePhpPoolDirectoryInspector;
use App\Infrastructure\Nodes\RemotePhpPackageManager;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;

it('reports the live pools whose working directory is missing on an app-dev Node', function (): void {
    $node = php_pool_inspector_node(RoleName::AppDev);
    $agents = new FakeAgentCommandRunner()->answer('php.inspect-shared-pools', ['versions' => [], 'missing_pools' => [
        ['pool' => 'orbit-app-instance-342', 'version' => '8.5', 'directory' => '/fast/apps/orbit-website/task-1172', 'installed' => true],
    ]]);

    $observations = php_pool_inspector($agents)->inspect($node);

    expect($observations)
        ->toEqual([new PhpPoolDirectoryObservation('orbit-app-instance-342', '8.5', '/fast/apps/orbit-website/task-1172', installed: true)])
        ->and($agents->deadlines[0])
        ->toBe(NativePhpPoolDirectoryInspector::ReadTimeoutSeconds)
        ->and($agents->calls)
        ->toHaveCount(1);
});

it('reads nothing on a Node without an active application role and maps a failed read to unverifiable', function (): void {
    $metrics = php_pool_inspector_node(RoleName::Metrics);
    $unread = new FakeAgentCommandRunner;
    $failing = new FakeAgentCommandRunner()->refuse('php.inspect-shared-pools', 'php.shared_pool_failed');

    expect(php_pool_inspector($unread)->inspect($metrics))
        ->toBe([])
        ->and($unread->calls)
        ->toBe([])
        ->and(fn () => php_pool_inspector($failing)->inspect(php_pool_inspector_node(RoleName::AppDev, 'failing')))
        ->toThrow(DoctorInspectionException::class);
});

function php_pool_inspector_node(RoleName $role, string $name = 'pool-node'): Node
{
    static $number = 40;
    $number++;
    $node = Node::query()->create([
        'name' => "{$name}-{$role->value}",
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => "192.0.2.{$number}",
        'wireguard_ip' => "10.44.0.{$number}",
        'user' => 'orbit',
    ]);
    $node->roles()->create(['role' => $role, 'status' => LifecycleStatus::Active]);

    return $node;
}

function php_pool_inspector(FakeAgentCommandRunner $agents): NativePhpPoolDirectoryInspector
{
    $accounts = new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
        }
    };

    return new NativePhpPoolDirectoryInspector(new RemoteAppDevPhpFpmManager(
        sites: new DevelopmentSiteRepository,
        renderer: new DevelopmentPhpFpmConfigRenderer,
        agents: $agents,
        accounts: $accounts,
        packages: new RemotePhpPackageManager,
    ));
}
