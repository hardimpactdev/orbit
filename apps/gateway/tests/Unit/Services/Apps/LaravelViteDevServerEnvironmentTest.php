<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\Project;
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
    $app = Project::factory()->for($node, 'node')->create([
        'name' => 'happie-nmbp',
        'domain' => 'happie.nmbp',
    ]);

    $variables = new LaravelViteDevServerEnvironment()->shellVariables($app, $node);

    expect($variables['VITE_DEV_SERVER_KEY'])
        ->toBe('/Users/nckrtl/.config/orbit/certs/happie.nmbp.key')
        ->and($variables['VITE_DEV_SERVER_CERT'])
        ->toBe('/Users/nckrtl/.config/orbit/certs/happie.nmbp.crt');
});
