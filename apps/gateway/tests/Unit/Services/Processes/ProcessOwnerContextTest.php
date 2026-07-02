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
