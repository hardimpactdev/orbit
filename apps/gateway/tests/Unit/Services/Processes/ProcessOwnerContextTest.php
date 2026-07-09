<?php

declare(strict_types=1);

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Node;
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

    $app = new ProcessOwnerContext($node, null, null, $node)->runtimeApp();

    expect($app->path)->toBe('/Users/nckrtl');
});

it('uses selected app instance placement for app-owned runtime apps', function (): void {
    /** @var Node $beast */
    $beast = Node::factory()->create([
        'name' => 'Beast',
        'platform' => 'ubuntu_24-04',
        'tld' => 'test',
    ]);
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
        'node_id' => $beast->id,
        'domain' => 'happie.test',
        'path' => '/home/nckrtl/apps/happie',
        'document_root' => 'public',
    ]);
    /** @var AppInstance $instance */
    $instance = AppInstance::factory()->for($app)->create([
        'name' => 'nmbp',
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $nmbp->id,
            node: 'NMBP',
            path: '/Users/nckrtl/apps/happie',
            document_root: 'public',
            domain: 'happie.nmbp',
        ),
    ]);

    $runtimeApp = new ProcessOwnerContext($nmbp, $app, null, $app, appInstance: $instance)->runtimeApp();

    expect($runtimeApp->name)
        ->toBe('happie')
        ->and($runtimeApp->path)
        ->toBe('/Users/nckrtl/apps/happie')
        ->and($runtimeApp->domain)
        ->toBe('happie.nmbp')
        ->and($runtimeApp->document_root)
        ->toBe('public')
        ->and($runtimeApp->node?->name)
        ->toBe('NMBP')
        ->and($runtimeApp->url())
        ->toBe('https://happie.nmbp');
});

it('carries dotted app instance selectors into process owner contexts', function (): void {
    /** @var Node $beast */
    $beast = Node::factory()->create([
        'name' => 'Beast',
        'platform' => 'ubuntu_24-04',
        'tld' => 'test',
    ]);
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
        'node_id' => $beast->id,
        'domain' => 'happie.test',
        'path' => '/home/nckrtl/apps/happie',
    ]);
    AppInstance::factory()->for($app)->create([
        'name' => 'nmbp',
        'driver_config' => new OrbitAppInstanceDriverConfigData(
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

    expect($context->node->name)
        ->toBe('NMBP')
        ->and($context->app?->name)
        ->toBe('happie')
        ->and($context->runtimeApp()->path)
        ->toBe('/Users/nckrtl/apps/happie')
        ->and($context->runtimeApp()->url())
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
