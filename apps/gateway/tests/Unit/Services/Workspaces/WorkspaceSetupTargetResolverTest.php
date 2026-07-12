<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Workspaces;

use App\Contracts\AgentIdeWorkspacePathResolver;
use App\Data\AgentIde\WorkspacePathResolution;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Enums\Apps\AppInstanceDriver;
use App\Enums\WorkspaceLifecycleStatus;
use App\Exceptions\WorkspaceSetupResolutionFailed;
use App\Models\App;
use App\Models\AppInstance;
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

    it('uses adapter identity for explicit Codex worktree paths without a workspace name', function (): void {
        $app = workspaceSetupResolverApp([
            'name' => 'happie',
            'path' => '/home/nckrtl/apps/happie',
            'agent_ide_config' => ['adapter' => 'polyscope'],
        ]);

        $localNode = createTestAppHostNode(role: 'app-dev');
        $instance = AppInstance::factory()
            ->for($app)
            ->create([
                'name' => 'nmbp',
                'driver' => AppInstanceDriver::Orbit,
                'driver_config' => new OrbitAppInstanceDriverConfigData(
                    node_id: $localNode->id,
                    node: 'NMBP',
                    path: '/Users/nckrtl/apps/happie',
                    document_root: 'public',
                    domain: 'happie.nmbp',
                ),
            ]);

        app()->instance(
            AgentIdeWorkspacePathResolver::class,
            new WorkspaceSetupTargetResolverFakePathResolver(new WorkspacePathResolution(
                workspaceName: 'codex-auto-env-happie-194238',
                appSlug: 'happie',
                path: '/Users/nckrtl/.codex/worktrees/fa33/happie',
                adapterWorkspaceId: 'codex:fa33',
            )),
        );

        [$workspace, $resolvedApp, $node, $isAdoption] = app(WorkspaceSetupTargetResolver::class)->resolve(
            name: null,
            appName: 'happie.nmbp',
            path: '/Users/nckrtl/.codex/worktrees/fa33/happie',
        );

        expect($workspace)
            ->toBeInstanceOf(Workspace::class)
            ->and($workspace->name)
            ->toBe('codex-auto-env-happie-194238')
            ->and($workspace->path)
            ->toBe('/Users/nckrtl/.codex/worktrees/fa33/happie')
            ->and($workspace->app_instance_id)
            ->toBe($instance->id)
            ->and($workspace->agent_ide)
            ->toBe('polyscope')
            ->and($workspace->agent_ide_workspace_id)
            ->toBe('codex:fa33')
            ->and($workspace->url())
            ->toBe('https://codex-auto-env-happie-194238.happie.nmbp')
            ->and($resolvedApp->is($app))
            ->toBeTrue()
            ->and($node->is($localNode))
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

    $app = App::factory()
        ->for($node, 'node')
        ->create($overrides);

    AppInstance::factory()
        ->for($app)
        ->create([
            'driver_config' => new OrbitAppInstanceDriverConfigData(
                node_id: $node->id,
                path: $app->path,
                document_root: $app->document_root,
                domain: $app->domain,
            ),
        ]);

    return $app;
}

final readonly class WorkspaceSetupTargetResolverFakePathResolver implements AgentIdeWorkspacePathResolver
{
    public function __construct(
        private WorkspacePathResolution $resolution,
    ) {}

    public function resolve(string $adapter, App $app, string $absolutePath): ?WorkspacePathResolution
    {
        if ($adapter !== 'polyscope') {
            return null;
        }

        if ($app->name !== $this->resolution->appSlug) {
            return null;
        }

        if ($absolutePath !== $this->resolution->path) {
            return null;
        }

        return $this->resolution;
    }
}
