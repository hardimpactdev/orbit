<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Services\Processes\LaunchdPlistRenderer;
use App\Services\Processes\ProcessDockerContainerRenderer;
use App\Services\Processes\ProcessOwnerContext;
use App\Services\Processes\ProcessOwnerContextResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orbit\Sdk\Laravel\GatewayApiException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('uses the macos node user home for node-owned runtime apps', function (): void {
    /** @var Node $node */
    $node = Node::factory()->create([
        'name' => 'mac-app-dev-1',
        'platform' => 'macos_14',
        'user' => 'nckrtl',
    ]);

    $context = new ProcessOwnerContext($node, null, null, $node);
    $process = Process::factory()->create([
        'owner_type' => $node->getMorphClass(),
        'owner_id' => $node->id,
        'node_id' => $node->id,
        'instance_id' => null,
        'runtime' => ProcessRuntime::Launchd,
        'name' => 'metrics',
        'command' => 'php artisan schedule:run',
    ]);

    // Node-owned host processes have no synthetic runtime app; the launchd unit
    // uses the node user home as its working directory.
    $plist = app(LaunchdPlistRenderer::class)->render($node, null, $process);

    expect($context->runtimeApp())
        ->toBeNull()
        ->and($plist)
        ->toContain('<key>WorkingDirectory</key>')
        ->and($plist)
        ->toContain('<string>/Users/nckrtl</string>');
});

it('uses selected app instance placement for app-owned runtime apps', function (): void {
    /** @var Node $nmbp */
    $nmbp = Node::factory()->create([
        'name' => 'NMBP',
        'platform' => 'darwin',
        'user' => 'nckrtl',
        'tld' => 'nmbp',
    ]);
    /** @var App $app */
    $app = App::factory()->create([
        'name' => 'happie',
    ]);
    /** @var Instance $instance */
    $instance = Instance::factory()->for($app)->create([
        'name' => 'nmbp',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $nmbp->id,
            node: 'NMBP',
            path: '/Users/nckrtl/apps/happie',
            document_root: 'public',
            domain: 'happie.nmbp',
        ),
    ]);

    $logicalApp = new ProcessOwnerContext($nmbp, $app, null, $app, instance: $instance)->runtimeApp();
    $process = Process::factory()->create([
        'owner_type' => App::class,
        'owner_id' => $app->id,
        'instance_id' => $instance->id,
        'node_id' => $nmbp->id,
        'runtime' => ProcessRuntime::Docker,
        'name' => 'queue',
        'command' => 'php artisan queue:work',
    ]);

    // runtimeApp is the logical app; the runtime resolves placement from the
    // selected instance, so the rendered container uses NMBP instance placement.
    $container = app(ProcessDockerContainerRenderer::class)->render($logicalApp, $process);
    $sources = array_column($container->mounts(), 'source');

    expect($logicalApp->name)
        ->toBe('happie')
        ->and($sources)
        ->toContain('/Users/nckrtl/apps/happie')
        ->and($container->environment()['APP_URL'])
        ->toBe('https://happie.nmbp');
});

it('carries dotted app instance selectors into process owner contexts', function (): void {
    /** @var Node $nmbp */
    $nmbp = Node::factory()->create([
        'name' => 'NMBP',
        'platform' => 'darwin',
        'user' => 'nckrtl',
        'tld' => 'nmbp',
    ]);
    /** @var App $app */
    $app = App::factory()->create([
        'name' => 'happie',
    ]);
    Instance::factory()->for($app)->create([
        'name' => 'nmbp',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $nmbp->id,
            node: 'NMBP',
            path: '/Users/nckrtl/apps/happie',
            domain: 'happie.nmbp',
        ),
    ]);

    $context = app(ProcessOwnerContextResolver::class)->resolve(
        nodeName: null,
        appName: 'happie.nmbp',
        workspaceName: null,
    );

    $instance = $context->instance;
    $process = Process::factory()->create([
        'owner_type' => App::class,
        'owner_id' => $context->app?->id,
        'instance_id' => $instance?->id,
        'node_id' => $context->node->id,
        'runtime' => ProcessRuntime::Docker,
        'name' => 'queue',
        'command' => 'php artisan queue:work',
    ]);

    $container = app(ProcessDockerContainerRenderer::class)->render($context->runtimeApp(), $process);
    $sources = array_column($container->mounts(), 'source');

    expect($context->node->name)
        ->toBe('NMBP')
        ->and($context->app?->name)
        ->toBe('happie')
        ->and($sources)
        ->toContain('/Users/nckrtl/apps/happie')
        ->and($container->environment()['APP_URL'])
        ->toBe('https://happie.nmbp');
});

it('rejects systemd process runtimes on macos nodes', function (): void {
    /** @var Node $node */
    $node = Node::factory()->create([
        'name' => 'mac-app-dev-1',
        'platform' => 'macos_14',
    ]);
    $context = new ProcessOwnerContext($node, null, null, $node);

    expect(fn () => $context->assertRuntimeAllowed(ProcessRuntime::Systemd))
        ->toThrow(GatewayApiException::class, 'The systemd runtime is only supported on Linux nodes.');
});

it('accepts launchd runtime on macos nodes for host-command processes', function (): void {
    /** @var Node $node */
    $node = Node::factory()->create([
        'name' => 'mac-app-dev-1',
        'platform' => 'darwin',
        'user' => 'nckrtl',
    ]);
    $context = new ProcessOwnerContext($node, null, null, $node);

    // should not throw; launchd allowed on mac for host commands
    $context->assertRuntimeAllowed(ProcessRuntime::Launchd);

    expect(ProcessRuntime::Launchd->value)->toBe('launchd');
});

it('defaults node-owned host commands to launchd on macos nodes', function (): void {
    /** @var Node $node */
    $node = Node::factory()->create([
        'name' => 'mac-app-dev-1',
        'platform' => 'macos_14',
        'user' => 'nckrtl',
    ]);
    $context = new ProcessOwnerContext($node, null, null, $node);

    expect($context->defaultRuntime())->toBe(ProcessRuntime::Launchd);
});

it('rejects launchd runtime on linux nodes with launchd_runtime_requires_macos', function (): void {
    /** @var Node $node */
    $node = Node::factory()->create([
        'name' => 'linux-app-dev-1',
        'platform' => 'ubuntu_24-04',
    ]);
    $context = new ProcessOwnerContext($node, null, null, $node);

    try {
        $context->assertRuntimeAllowed(ProcessRuntime::Launchd);
    } catch (GatewayApiException $exception) {
        expect($exception->getMessage())
            ->toContain('launchd')
            ->and($exception->errorMeta()['reason'])
            ->toBe('launchd_runtime_requires_macos');

        return;
    }

    expect()->fail('Expected launchd runtime validation to fail on Linux nodes.');
});
