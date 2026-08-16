<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Doctor\DoctorWorkspaceRestorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('returns the existing skipped action for the workspace placed on the target node', function (): void {
    $firstNode = Node::factory()->create(['name' => 'app-dev-1']);
    $secondNode = Node::factory()->create(['name' => 'app-dev-2']);
    $firstApp = App::factory()->placedOn($firstNode)->create(['name' => 'docs']);
    $secondApp = App::factory()->placedOn($secondNode)->create(['name' => 'api']);

    Workspace::factory()
        ->for($firstApp, 'app')
        ->for($firstApp->instances()->firstOrFail(), 'instance')
        ->create(['name' => 'feature']);
    Workspace::factory()
        ->for($secondApp, 'app')
        ->for($secondApp->instances()->firstOrFail(), 'instance')
        ->create(['name' => 'feature']);

    $action = app(DoctorWorkspaceRestorer::class)->apply(
        $secondNode,
        'workspace.runtime_config_mismatch',
        ['workspace' => 'feature'],
    );

    expect($action)->toBe([
        'family' => 'workspace',
        'node' => 'app-dev-2',
        'code' => 'workspace.runtime_config_mismatch',
        'key' => 'workspace.runtime_config_mismatch',
        'mode' => 'restore',
        'status' => 'skipped',
        'summary' => 'Skipped fix for workspace.runtime_config_mismatch: workspace auto-fix is not supported in the Docker-first runtime.',
    ]);
});

it('returns null when workspace identity or placement does not match', function (): void {
    $workspaceNode = Node::factory()->create(['name' => 'workspace-node']);
    $otherNode = Node::factory()->create(['name' => 'other-node']);
    $app = App::factory()->placedOn($workspaceNode)->create(['name' => 'docs']);

    Workspace::factory()
        ->for($app, 'app')
        ->for($app->instances()->firstOrFail(), 'instance')
        ->create(['name' => 'feature']);

    $restorer = app(DoctorWorkspaceRestorer::class);

    expect($restorer->apply($otherNode, 'workspace.path_missing', [
        'app' => 'docs',
        'workspace' => 'feature',
    ]))
        ->toBeNull()
        ->and($restorer->apply($workspaceNode, 'workspace.path_missing', []))
        ->toBeNull();
});
