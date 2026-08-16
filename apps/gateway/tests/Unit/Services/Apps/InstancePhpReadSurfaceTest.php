<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Services\Apps\InstancePayloads;
use App\Services\Php\PhpRuntimeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function read_surface_instance(App $app, Node $node, string $name, ?string $phpVersion): Instance
{
    return Instance::factory()->for($app)->create([
        'name' => $name,
        'php_version' => $phpVersion,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '/home/orbit/apps/'.$app->name,
            document_root: 'public',
            domain: null,
        ),
    ]);
}

it('reports the instance version in the instance runtime payload', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'app-1']);
    $app = App::factory()->create(['name' => 'docs', 'php_version' => '8.5']);
    $instance = read_surface_instance($app, $node, 'production', '8.3');

    $payload = app(InstancePayloads::class)->withCompatibility($instance);
    $runtime = $payload['instance']['runtime'];

    expect($runtime['php_version'])
        ->toBe('8.3')
        ->and($runtime['frankenphp_image'])
        ->toContain('php8.3')
        ->and($payload['cloud_compatibility']['php_version']['version'])
        ->toBe('8.3');
});

it('reports the instance version in the php runtime view', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'app-1']);
    $app = App::factory()->create(['name' => 'docs', 'php_version' => '8.5']);
    read_surface_instance($app, $node, 'production', '8.3');

    $php = app(PhpRuntimeManager::class)->view(instance: 'docs.production')->payload;

    expect($php['instance']['php_version'])
        ->toBe('8.3')
        ->and($php['app']['php_version'])
        ->toBe('8.5');
});
