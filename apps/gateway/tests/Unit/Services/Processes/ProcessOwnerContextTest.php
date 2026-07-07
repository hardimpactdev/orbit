<?php

declare(strict_types=1);

use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Services\Processes\ProcessOwnerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orbit\Sdk\Laravel\GatewayApiException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('uses the macos node user home for node-owned runtime apps', function (): void {
    $node = Node::factory()->create([
        'name' => 'mac-app-dev-1',
        'platform' => 'macos_14',
        'user' => 'nckrtl',
    ]);

    $app = new ProcessOwnerContext($node, null, null, $node)->runtimeApp();

    expect($app->path)->toBe('/Users/nckrtl');
});

it('rejects systemd process runtimes on macos nodes', function (): void {
    $node = Node::factory()->create([
        'name' => 'mac-app-dev-1',
        'platform' => 'macos_14',
    ]);
    $context = new ProcessOwnerContext($node, null, null, $node);

    expect(fn () => $context->assertRuntimeAllowed(ProcessRuntime::Systemd))
        ->toThrow(GatewayApiException::class, 'The systemd runtime is only supported on Linux nodes.');
});

it('accepts launchd runtime on macos nodes for host-command processes', function (): void {
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
    $node = Node::factory()->create([
        'name' => 'mac-app-dev-1',
        'platform' => 'macos_14',
        'user' => 'nckrtl',
    ]);
    $context = new ProcessOwnerContext($node, null, null, $node);

    expect($context->defaultRuntime())->toBe(ProcessRuntime::Launchd);
});

it('rejects launchd runtime on linux nodes with launchd_runtime_requires_macos', function (): void {
    $node = Node::factory()->create([
        'name' => 'linux-app-dev-1',
        'platform' => 'ubuntu_24-04',
    ]);
    $context = new ProcessOwnerContext($node, null, null, $node);

    expect(fn () => $context->assertRuntimeAllowed(ProcessRuntime::Launchd))
        ->toThrow(GatewayApiException::class)
        ->and(fn () => $context->assertRuntimeAllowed(ProcessRuntime::Launchd))
        ->toThrow(
            fn (GatewayApiException $e) => (
                str_contains($e->getMessage(), 'launchd')
                || $e->errorMeta()['reason'] === 'launchd_runtime_requires_macos'
            ),
        );
});
