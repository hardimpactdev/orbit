<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Workspaces;

use App\Enums\WorkspaceLifecycleStatus;
use App\Exceptions\WorkspaceSetupResolutionFailed;
use App\Models\App;
use App\Models\Workspace;
use App\Services\Workspaces\WorkspaceSetupTargetResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

describe('explicit path adoption', function (): void {
    it('registers workspace paths outside the parent app path', function (): void {
        $app = workspaceSetupResolverApp([
            'name' => 'dngdmt',
            'path' => '/home/nckrtl/apps/dngdmt',
        ]);

        [$workspace, $resolvedApp, $node, $isAdoption] = app(WorkspaceSetupTargetResolver::class)->resolve(
            name: 'Y-Fol-DNG-202603-020',
            appName: 'dngdmt',
            path: '/home/nckrtl/.codex/worktrees/9106/dngdmt',
        );

        expect($workspace)
            ->toBeInstanceOf(Workspace::class)
            ->and($workspace->name)
            ->toBe('Y-Fol-DNG-202603-020')
            ->and($workspace->path)
            ->toBe('/home/nckrtl/.codex/worktrees/9106/dngdmt')
            ->and($workspace->lifecycle_status)
            ->toBe(WorkspaceLifecycleStatus::SetupPending)
            ->and($resolvedApp->is($app))
            ->toBeTrue()
            ->and($node->is($app->node))
            ->toBeTrue()
            ->and($isAdoption)
            ->toBeTrue();
    });

    it('rejects the parent app root as an explicit workspace path', function (): void {
        workspaceSetupResolverApp([
            'name' => 'dngdmt',
            'path' => '/home/nckrtl/apps/dngdmt',
        ]);

        expect(fn () => app(WorkspaceSetupTargetResolver::class)->resolve(
            name: 'Y-Fol-DNG-202603-020',
            appName: 'dngdmt',
            path: '/home/nckrtl/apps/dngdmt',
        ))
            ->toThrow(WorkspaceSetupResolutionFailed::class, 'app root');
    });
});

function workspaceSetupResolverApp(array $overrides = []): App
{
    $node = createTestAppHostNode(role: 'app-dev');

    return App::factory()
        ->for($node, 'node')
        ->create($overrides);
}
