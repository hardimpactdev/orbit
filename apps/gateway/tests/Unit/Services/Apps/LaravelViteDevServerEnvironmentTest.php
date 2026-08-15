<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Services\Apps\LaravelViteDevServerEnvironment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('uses macOS home paths for host Vite certificate variables', function (): void {
    $node = Node::factory()->create([
        'name' => 'NMBP',
        'platform' => 'darwin',
        'user' => 'nckrtl',
        'tld' => 'nmbp',
    ]);
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'happie-nmbp',
        'domain' => 'happie.nmbp',
    ]);
    $instance = Instance::factory()->for($app)->create([
        'name' => 'development',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '/Users/nckrtl/apps/happie',
            document_root: 'public',
            domain: 'happie.nmbp',
        ),
    ]);

    $variables = new LaravelViteDevServerEnvironment()->shellVariables($app, $node, null, $instance);

    expect($variables['VITE_DEV_SERVER_KEY'])
        ->toBe('/Users/nckrtl/.config/orbit/certs/happie.nmbp.key')
        ->and($variables['VITE_DEV_SERVER_CERT'])
        ->toBe('/Users/nckrtl/.config/orbit/certs/happie.nmbp.crt');
});
