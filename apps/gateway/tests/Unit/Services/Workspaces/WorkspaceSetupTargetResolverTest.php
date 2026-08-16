<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Workspaces;

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Enums\Apps\InstanceDriver;
use App\Enums\WorkspaceLifecycleStatus;
use App\Exceptions\WorkspaceSetupResolutionFailed;
use App\Models\App;
use App\Models\Instance;
use App\Models\Workspace;
use App\Services\Workspaces\WorkspacePlacement;
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
            name: 'y-fol-dng-202603-020',
            appName: 'dngdmt',
            path: '/home/nckrtl/.codex/worktrees/9106/dngdmt',
        );

        expect($workspace)
            ->toBeInstanceOf(Workspace::class)
            ->and($workspace->name)
            ->toBe('y-fol-dng-202603-020')
            ->and($workspace->path)
            ->toBe('/home/nckrtl/.codex/worktrees/9106/dngdmt')
            ->and($workspace->lifecycle_status)
            ->toBe(WorkspaceLifecycleStatus::SetupPending)
            ->and($resolvedApp->is($app))
            ->toBeTrue()
            ->and($node->is(app(WorkspacePlacement::class)->runtimeNode($app, null)))
            ->toBeTrue()
            ->and($isAdoption)
            ->toBeTrue();
    });

    it('uses basename for explicit Codex worktree paths without a workspace name', function (): void {
        $app = workspaceSetupResolverApp([
            'name' => 'happie',
            'path' => '/home/nckrtl/apps/happie',
        ]);

        $localNode = createTestAppHostNode(role: 'app-dev');
        $instance = Instance::factory()
            ->for($app)
            ->create([
                'name' => 'nmbp',
                'driver' => InstanceDriver::Orbit,
                'driver_config' => new OrbitInstanceDriverConfigData(
                    node_id: $localNode->id,
                    node: 'NMBP',
                    path: '/Users/nckrtl/apps/happie',
                    document_root: 'public',
                    domain: 'happie.nmbp',
                ),
            ]);

        [$workspace, $resolvedApp, $node, $isAdoption] = app(WorkspaceSetupTargetResolver::class)->resolve(
            name: null,
            appName: 'happie.nmbp',
            path: '/Users/nckrtl/.codex/worktrees/fa33/happie',
        );

        expect($workspace)
            ->toBeInstanceOf(Workspace::class)
            ->and($workspace->name)
            ->toBe('happie')
            ->and($workspace->path)
            ->toBe('/Users/nckrtl/.codex/worktrees/fa33/happie')
            ->and($workspace->instance_id)
            ->toBe($instance->id)
            ->and($workspace->url())
            ->toBe('https://happie.happie.nmbp')
            ->and($resolvedApp->is($app))
            ->toBeTrue()
            ->and($node->is($localNode))
            ->toBeTrue()
            ->and($isAdoption)
            ->toBeTrue();
    });

    it('rejects an invalid explicit workspace identity before setup resolution', function (): void {
        $app = workspaceSetupResolverApp([
            'name' => 'happie',
            'path' => '/home/nckrtl/apps/happie',
        ]);

        try {
            app(WorkspaceSetupTargetResolver::class)->resolve(
                name: "feature'; touch /tmp/orbit-invalid",
                appName: 'happie.development',
                path: '/home/nckrtl/apps/happie/.worktrees/feature-a',
            );

            $this->fail('Expected the invalid workspace identity to be rejected.');
        } catch (WorkspaceSetupResolutionFailed $exception) {
            expect($exception->errorCode)
                ->toBe('validation_failed')
                ->and($exception->meta)
                ->toBe([
                    'field' => 'name',
                    'reason' => 'slug_regex',
                ]);
        }

        expect(Workspace::query()->where('app_id', $app->id)->exists())->toBeFalse();
    });

    it('rejects an invalid basename-derived adoption identity before registration', function (): void {
        $app = workspaceSetupResolverApp([
            'name' => 'happie',
            'path' => '/home/nckrtl/apps/happie',
        ]);

        try {
            app(WorkspaceSetupTargetResolver::class)->resolve(
                name: null,
                appName: 'happie.development',
                path: "/home/nckrtl/apps/happie/.worktrees/feature'; touch orbit-invalid",
            );

            $this->fail('Expected the basename-derived workspace identity to be rejected.');
        } catch (WorkspaceSetupResolutionFailed $exception) {
            expect($exception->errorCode)
                ->toBe('validation_failed')
                ->and($exception->meta)
                ->toBe([
                    'field' => 'name',
                    'reason' => 'slug_regex',
                ]);
        }

        expect(Workspace::query()->where('app_id', $app->id)->exists())->toBeFalse();
    });

    it('rejects the parent project root as an explicit workspace path', function (): void {
        workspaceSetupResolverApp([
            'name' => 'dngdmt',
            'path' => '/home/nckrtl/apps/dngdmt',
        ]);

        expect(fn () => app(WorkspaceSetupTargetResolver::class)->resolve(
            name: 'y-fol-dng-202603-020',
            appName: 'dngdmt',
            path: '/home/nckrtl/apps/dngdmt',
        ))
            ->toThrow(WorkspaceSetupResolutionFailed::class, 'project root');
    });

    it('rejects production placement before workspace registration', function (): void {
        $node = createTestAppHostNode(role: 'app-prod');
        $app = App::factory()->create([
            'name' => 'site',
        ]);
        Instance::factory()->for($app)->create([
            'name' => 'development',
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $node->id,
                path: '/srv/site',
                document_root: 'public',
                domain: null,
            ),
        ]);

        try {
            app(WorkspaceSetupTargetResolver::class)->resolve(
                name: null,
                appName: 'site.development',
                path: '/srv/site/.worktrees/feature',
            );

            $this->fail('Expected production workspace placement to be rejected.');
        } catch (WorkspaceSetupResolutionFailed $exception) {
            expect($exception->errorCode)
                ->toBe('workspace.unsupported_for_production')
                ->and($exception->meta['node'] ?? null)
                ->toBe($node->name)
                ->and($exception->meta['role'] ?? null)
                ->toBe('app-prod');
        }

        expect(Workspace::query()->where('app_id', $app->id)->exists())->toBeFalse();
    });
});

function workspaceSetupResolverApp(array $overrides = []): App
{
    $node = createTestAppHostNode(role: 'app-dev');

    $path = isset($overrides['path']) && is_string($overrides['path']) ? $overrides['path'] : null;
    $documentRoot = isset($overrides['document_root']) && is_string($overrides['document_root'])
        ? $overrides['document_root']
        : 'public';
    $domain = isset($overrides['domain']) && is_string($overrides['domain']) ? $overrides['domain'] : null;
    unset(
        $overrides['path'],
        $overrides['document_root'],
        $overrides['domain'],
        $overrides['node_id'],
        $overrides['environment'],
    );

    /** @var App $app */
    $app = App::factory()
        ->placedOn($node, 'development', $path, $documentRoot, $domain)
        ->create($overrides);

    return $app;
}
