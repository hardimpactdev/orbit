<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Exceptions\AppSelectionResolutionFailed;
use App\Models\App;
use App\Models\Instance;
use App\Services\Apps\AppSelectorResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves exact instance names before node domain path or tld aliases', function (): void {
    $nmbpNode = createTestAppHostNode([
        'name' => 'nmbp',
        'tld' => 'nmbp',
    ]);

    $app = App::factory()->create([
        'name' => 'mealou',
    ]);

    $nmbp = Instance::factory()->for($app)->create([
        'name' => 'nmbp',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $nmbpNode->id,
            node: $nmbpNode->name,
            path: '/srv/mealou',
            domain: 'mealou.nmbp',
        ),
    ]);

    Instance::factory()->for($app)->create([
        'name' => 'development',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $nmbpNode->id,
            node: $nmbpNode->name,
            path: '/srv/mealou-development',
            domain: 'mealou.development',
        ),
    ]);

    $resolver = app(AppSelectorResolver::class);

    $byExactNmbp = $resolver->resolve('mealou.nmbp');
    $byExactDevelopment = $resolver->resolve('mealou.development');

    expect($byExactNmbp?->instance?->is($nmbp))
        ->toBeTrue()
        ->and($byExactNmbp?->instanceSelector)
        ->toBe('nmbp')
        ->and($byExactDevelopment?->instance?->name)
        ->toBe('development')
        ->and($byExactDevelopment?->instanceSelector)
        ->toBe('development');
});

it('keeps bare project selectors ambiguous when multiple instances exist', function (): void {
    $node = createTestAppHostNode(['name' => 'app-1']);
    $app = App::factory()->create([
        'name' => 'mealou',
    ]);

    Instance::factory()->for($app)->create([
        'name' => 'nmbp',
        'driver_config' => new OrbitInstanceDriverConfigData(node_id: $node->id),
    ]);
    Instance::factory()->for($app)->create([
        'name' => 'development',
        'driver_config' => new OrbitInstanceDriverConfigData(node_id: $node->id),
    ]);

    $resolver = app(AppSelectorResolver::class);
    $selection = $resolver->resolve('mealou');

    expect($selection?->instance)->toBeNull()->and($selection?->app->name)->toBe('mealou');

    expect(fn () => $resolver->requireInstance($selection))
        ->toThrow(AppSelectionResolutionFailed::class);
});
