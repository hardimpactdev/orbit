<?php

declare(strict_types=1);

use App\Domain\Hibernation\HibernationException;
use App\Domain\Hibernation\LocalRuntimeDependencies;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\Hibernation\RemoteInstanceCheckoutInspector;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use Tests\Support\FakeAgentCommandRunner;
use Tests\TestCase;

uses(TestCase::class);

it('classifies reconstructable vendor and node_modules from the agent checkout facts', function (): void {
    $agents = new FakeAgentCommandRunner()->answer('hibernation.inspect-checkout', [
        'composer_json' => true, 'composer_lock' => true, 'vendor_present' => true, 'vendor_symlink' => false,
        'package_json' => true, 'node_modules_present' => true, 'node_modules_symlink' => false,
        'javascript_locks' => ['package-lock.json'], 'source_mtime' => 100,
    ]);

    $state = checkout_inspector($agents)->inspect(checkout_instance());

    expect($state->prunableVendor())->toBeTrue()
        ->and($state->prunableNodeModules())->toBeTrue()
        ->and($state->sourceTreeLastActivityUnix)->toBe(100)
        ->and($agents->calls[0]['input'])->toBe(['repository' => '/home/orbit/apps/docs', 'checkout' => '/home/orbit/apps/docs']);
});

it('inspects and prunes the application directory of a nested Laravel app and dates edits by the whole checkout', function (): void {
    $agents = new FakeAgentCommandRunner()
        ->answer('hibernation.inspect-checkout', ['composer_json' => true, 'composer_lock' => true, 'vendor_present' => true, 'javascript_locks' => [], 'source_mtime' => 200])
        ->answer('hibernation.prune-checkout', []);
    $instance = checkout_instance();
    $instance->forceFill(['root' => 'server/web/public']);
    $inspector = checkout_inspector($agents);

    $inspector->prune($instance, $inspector->inspect($instance));

    expect($agents->calls[0]['input'])->toBe(['repository' => '/home/orbit/apps/docs', 'checkout' => '/home/orbit/apps/docs/server/web'])
        ->and($agents->calls[1]['input'])->toBe(['checkout' => '/home/orbit/apps/docs/server/web', 'targets' => ['vendor']]);
});

it('prunes nothing that cannot be reconstructed', function (): void {
    $agents = new FakeAgentCommandRunner;
    $state = LocalRuntimeDependencies::inspect(
        composerJsonFile: true,
        composerLockFile: false,
        vendorPresent: true,
        vendorSymlink: false,
        packageJsonFile: false,
        javascriptLockFiles: [],
        nodeModulesPresent: false,
        nodeModulesSymlink: false,
        sourceTreeLastActivityUnix: 100,
    );

    checkout_inspector($agents)->prune(checkout_instance(), $state);

    expect($agents->calls)->toBe([]);
});

it('restores missing dependencies as the managed user within the cold wake deadline', function (): void {
    $agents = new FakeAgentCommandRunner()->answer('hibernation.restore-dependencies', []);
    $state = LocalRuntimeDependencies::inspect(
        composerJsonFile: true,
        composerLockFile: true,
        vendorPresent: false,
        vendorSymlink: false,
        packageJsonFile: true,
        javascriptLockFiles: ['pnpm-lock.yaml'],
        nodeModulesPresent: false,
        nodeModulesSymlink: false,
        sourceTreeLastActivityUnix: 100,
    );

    checkout_inspector($agents)->restore(checkout_instance(), $state);

    expect($agents->calls[0]['input'])->toBe(['user' => 'orbit', 'checkout' => '/home/orbit/apps/docs', 'composer' => true, 'javascript' => true]);
});

it('names the restore step that failed', function (): void {
    $agents = new FakeAgentCommandRunner()->refuse('hibernation.restore-dependencies', 'hibernation.node_failed', ['step' => 'restore-javascript']);
    $state = LocalRuntimeDependencies::inspect(
        composerJsonFile: false,
        composerLockFile: false,
        vendorPresent: false,
        vendorSymlink: false,
        packageJsonFile: true,
        javascriptLockFiles: ['pnpm-lock.yaml'],
        nodeModulesPresent: false,
        nodeModulesSymlink: false,
        sourceTreeLastActivityUnix: 100,
    );

    expect(fn () => checkout_inspector($agents)->restore(checkout_instance(), $state))
        ->toThrow(HibernationException::class, 'Checkout dependency restore step [restore-javascript] failed on Instance [main].');
});

function checkout_inspector(FakeAgentCommandRunner $agents, int $timeout = 1_800): RemoteInstanceCheckoutInspector
{
    return new RemoteInstanceCheckoutInspector(
        agents: $agents,
        accounts: new class implements ManagedUserAccountResolver
        {
            public function resolve(Node $node): ManagedUserAccount
            {
                return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
            }
        },
        restoreTimeoutSeconds: $timeout,
    );
}

function checkout_instance(): Instance
{
    $node = new Node([
        'name' => 'app-dev',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.3',
    ]);
    $node->setRelation('roles', collect([new NodeRole(['role' => RoleName::AppDev])]));
    $instance = new Instance([
        'root' => 'public',
        'source_is_laravel' => true,
        'name' => 'main',
        'environment' => 'development',
        'checkout_path' => '/home/orbit/apps/docs',
    ]);
    $instance->setRelation('node', $node);

    return $instance;
}
