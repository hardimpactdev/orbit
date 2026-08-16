<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Services\Apps\AppRuntimeUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/** @param array<string, mixed> $overrides */
function appRuntimeUserTestApp(Node $node, array $overrides = []): App
{
    // Placement lives on the concrete instance now: the old App `environment`
    // and `path` shadows map to the instance name and driver-config path, and
    // production-ness is derived from the serving node role.
    $name = is_string($overrides['name'] ?? null) ? $overrides['name'] : 'docs';
    $instanceName = is_string($overrides['environment'] ?? null) ? $overrides['environment'] : 'production';
    $path = is_string($overrides['path'] ?? null) ? $overrides['path'] : '/home/docs/app';

    $app = App::factory()->create(['name' => $name]);

    Instance::factory()->for($app, 'app')->create([
        'name' => $instanceName,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: $path,
            document_root: 'public',
            domain: null,
        ),
    ]);

    return $app;
}

it('resolves production runtime user from a home path', function (): void {
    $node = Node::factory()->appProd()->create(['user' => 'orbit']);
    $app = appRuntimeUserTestApp($node, [
        'environment' => 'production',
        'path' => '/home/docs/app',
    ]);

    expect(app(AppRuntimeUser::class)->forApp($app))->toBe('docs');
});

it('falls back to node user when the app path is not under home', function (): void {
    $node = Node::factory()->appProd()->create(['user' => 'orbit']);
    $app = appRuntimeUserTestApp($node, [
        'environment' => 'production',
        'path' => '/srv/docs',
    ]);

    expect(app(AppRuntimeUser::class)->forApp($app))->toBe('orbit');
});

it('keeps development apps on the node steady-state user', function (): void {
    $node = Node::factory()->appDev()->create(['user' => 'orbit']);
    $app = appRuntimeUserTestApp($node, [
        'environment' => 'development',
        'path' => '/home/docs/app',
    ]);

    expect(app(AppRuntimeUser::class)->forApp($app))->toBe('orbit');
});

it('exposes container users for production apps only', function (): void {
    $productionNode = Node::factory()->appProd()->create(['user' => 'orbit']);
    $developmentNode = Node::factory()->appDev()->create(['user' => 'orbit']);

    $productionApp = appRuntimeUserTestApp($productionNode, [
        'environment' => 'production',
        'path' => '/home/docs/app',
    ]);
    $developmentApp = appRuntimeUserTestApp($developmentNode, [
        'name' => 'docs-dev',
        'environment' => 'development',
        'path' => '/home/docs/app',
    ]);

    $resolver = app(AppRuntimeUser::class);

    expect($resolver->containerUserForApp($productionApp))
        ->toBe('docs')
        ->and($resolver->containerUserForApp($developmentApp))
        ->toBeNull();
});
