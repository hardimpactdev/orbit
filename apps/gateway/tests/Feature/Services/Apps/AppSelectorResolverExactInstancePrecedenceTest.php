<?php

declare(strict_types=1);

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Exceptions\AppSelectionResolutionFailed;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Project;
use App\Services\Apps\AppSelectorResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Sibling instances that share a node/domain/TLD must not make an exact
 * project.instance name selector ambiguous.
 */
it('resolves exact instance names before node domain path or tld aliases', function (): void {
    $nmbpNode = createTestAppHostNode([
        'name' => 'nmbp',
        'tld' => 'nmbp',
    ]);
    $developmentNode = createTestAppHostNode([
        'name' => 'development-host',
        'tld' => 'development',
    ]);

    $project = Project::factory()->create([
        'name' => 'mealou',
        'node_id' => $nmbpNode->id,
        'domain' => 'mealou.test',
        'path' => '/srv/mealou',
    ]);

    $nmbp = AppInstance::factory()->for($project)->create([
        'name' => 'nmbp',
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $nmbpNode->id,
            node: $nmbpNode->name,
            path: '/srv/mealou',
            domain: 'mealou.nmbp',
        ),
    ]);

    // Sibling shares the same node name, path root, and a domain that includes
    // the sibling selector token — alias matching alone would collide.
    AppInstance::factory()->for($project)->create([
        'name' => 'development',
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $nmbpNode->id,
            node: $nmbpNode->name,
            path: '/srv/mealou-development',
            domain: 'mealou.development',
        ),
    ]);

    AppInstance::factory()->for($project)->create([
        'name' => 'staging',
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $developmentNode->id,
            node: $developmentNode->name,
            path: '/srv/mealou-staging',
            domain: 'mealou.development',
        ),
    ]);

    $resolver = app(AppSelectorResolver::class);

    $byExactNmbp = $resolver->resolve('mealou.nmbp');
    $byExactDevelopment = $resolver->resolve('mealou.development');

    expect($byExactNmbp?->instance?->is($nmbp))->toBeTrue()
        ->and($byExactNmbp?->instanceSelector)->toBe('nmbp')
        ->and($byExactDevelopment?->instance?->name)->toBe('development')
        ->and($byExactDevelopment?->instanceSelector)->toBe('development');
});

it('keeps bare project selectors ambiguous when multiple instances exist', function (): void {
    $node = createTestAppHostNode(['name' => 'app-1']);
    $project = Project::factory()->create([
        'name' => 'mealou',
        'node_id' => $node->id,
    ]);

    AppInstance::factory()->for($project)->create(['name' => 'nmbp']);
    AppInstance::factory()->for($project)->create(['name' => 'development']);

    $resolver = app(AppSelectorResolver::class);
    $selection = $resolver->resolve('mealou');

    expect($selection?->instance)->toBeNull()
        ->and($selection?->app->name)->toBe('mealou');

    expect(fn () => $resolver->requireInstance($selection))
        ->toThrow(AppSelectionResolutionFailed::class);
});

it('still resolves alias selectors when no exact instance name matches', function (): void {
    $node = createTestAppHostNode([
        'name' => 'beast',
        'tld' => 'beast',
    ]);
    $project = Project::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
    ]);

    $instance = AppInstance::factory()->for($project)->create([
        'name' => 'production',
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '/srv/docs',
            domain: 'docs.beast',
        ),
    ]);

    $resolver = app(AppSelectorResolver::class);
    $byNode = $resolver->resolve('docs.beast');
    $byTld = $resolver->resolve('docs.beast');

    expect($byNode?->instance?->is($instance))->toBeTrue()
        ->and($byTld?->instance?->is($instance))->toBeTrue();
});
