<?php

declare(strict_types=1);

use App\Actions\Workspaces\CreateWorkspace;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Data\Workspaces\WorkspaceProvisionResult;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Workspaces\WorkspaceSetupTargetResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('copies the owning instance version onto a workspace created without an override', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'app-1']);
    $app = App::factory()->create(['name' => 'docs', 'php_version' => '8.5']);
    $instance = Instance::factory()->for($app)->create(['name' => 'development', 'php_version' => '8.3']);

    $workspace = app(CreateWorkspace::class)->createIntent(
        $app,
        $instance,
        null,
        new WorkspaceProvisionResult('feature-docs', '/srv/orbit/docs/feature-docs'),
    );

    expect($workspace->refresh()->php_version)->toBe('8.3');
});

it('keeps an explicit workspace version ahead of the owning instance version', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'app-1']);
    $app = App::factory()->create(['name' => 'docs', 'php_version' => '8.5']);
    $instance = Instance::factory()->for($app)->create(['name' => 'development', 'php_version' => '8.3']);

    $workspace = app(CreateWorkspace::class)->createIntent(
        $app,
        $instance,
        '8.4',
        new WorkspaceProvisionResult('pinned-docs', '/srv/orbit/docs/pinned-docs'),
    );

    expect($workspace->refresh()->php_version)->toBe('8.4');
});

it('resolves a workspace to its own stored version', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'app-1']);
    $app = App::factory()->create(['name' => 'docs', 'php_version' => '8.5']);
    $instance = Instance::factory()->for($app)->create(['name' => 'development', 'php_version' => '8.3']);
    $workspace = Workspace::factory()->create([
        'app_id' => $app->id,
        'instance_id' => $instance->id,
        'name' => 'feature-docs',
        'php_version' => '8.3',
    ]);

    expect($workspace->effectivePhpVersion())->toBe('8.3');
});

it('keeps an invalid null workspace snapshot detectable', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'app-1']);
    $app = App::factory()->create(['name' => 'docs', 'php_version' => '8.5']);
    $instance = Instance::factory()->for($app)->create(['name' => 'development', 'php_version' => '8.3']);
    $workspace = Workspace::factory()->create([
        'app_id' => $app->id,
        'instance_id' => $instance->id,
        'name' => 'legacy',
        'php_version' => null,
    ]);

    expect($workspace->effectivePhpVersion())->toBeNull();
});

it('does not move a workspace when the app default changes', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'app-1']);
    $app = App::factory()->create(['name' => 'docs', 'php_version' => '8.4']);
    $instance = Instance::factory()->for($app)->create(['name' => 'development', 'php_version' => '8.4']);
    $workspace = Workspace::factory()->create([
        'app_id' => $app->id,
        'instance_id' => $instance->id,
        'name' => 'feature-docs',
        'php_version' => '8.4',
    ]);

    $app->forceFill(['php_version' => '8.5'])->save();

    expect($workspace->refresh()->effectivePhpVersion())->toBe('8.4');
});

it('stamps the owning instance version onto a workspace adopted by path', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'app-1']);
    $app = App::factory()->create([
        'name' => 'docs',
        'php_version' => '8.5',
    ]);
    $instance = Instance::factory()->for($app)->create([
        'name' => 'development',
        'php_version' => '8.3',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '/srv/docs',
            document_root: 'public',
        ),
    ]);

    // The public entry point the setup controller itself calls.
    app(WorkspaceSetupTargetResolver::class)->resolve(
        null,
        'docs.development',
        '/srv/docs/.worktrees/adopted',
        null,
        $node,
    );

    $workspace = Workspace::query()->where('name', 'adopted')->sole();

    expect($workspace->php_version)->toBe('8.3');

    $instance->forceFill(['php_version' => '8.4'])->save();

    expect($workspace->refresh()->php_version)->toBe('8.3');
});
