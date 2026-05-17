<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Workspaces;

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Enums\WorkspaceLifecycleStatus;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Workspaces\WorkspacesProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->probe = new WorkspacesProbe;
});

describe('interface contract', function (): void {
    it('has key and label', function (): void {
        expect($this->probe->key())->toBe('workspace');
        expect($this->probe->label())->toBe('Workspaces');
    });

    it('returns empty snapshot from introspect', function (): void {
        $workspace = new Workspace(['name' => 'feature']);
        $snapshot = $this->probe->introspect($workspace);

        expect($snapshot->isEmpty())->toBeTrue();
    });
});

describe('source path reality', function (): void {
    it('introspects workspace source path reality on the parent app node', function (): void {
        $app = workspaceableApp();
        $workspace = Workspace::factory()
            ->for($app, 'app')
            ->create([
                'name' => 'feature',
                'path' => "{$app->path}/.worktrees/feature",
            ]);
        $shell = new WorkspacesProbeRecordingRemoteShell("feature\t1\t1\t1\t1\t1\n");

        $snapshot = (new WorkspacesProbe($shell))->introspect($workspace);

        expect($snapshot->get('feature'))->toMatchArray([
            'path_exists' => true,
            'path_usable' => true,
            'php_fpm_available' => true,
            'fpm_config_exists' => true,
            'fpm_config_matches' => true,
        ]);
        expect($shell->scripts[0])->toContain('ORBIT_WORKSPACE_SPEC');
        expect($shell->nodes[0]->is($app->node))->toBeTrue();
    });

    it('detects missing workspace paths', function (): void {
        $app = workspaceableApp();
        $workspace = workspaceFor($app, ['name' => 'feature']);

        $snapshot = new ProbeSnapshot([
            'feature' => [
                'path_exists' => false,
                'path_usable' => false,
            ],
        ]);

        $drift = (new WorkspacesProbe)->diff($workspace, $snapshot);

        expect(issue($drift, 'workspace.path_missing')?->kind)->toBe(DriftKind::Missing);
        expect(issue($drift, 'workspace.path_unusable'))->toBeNull();
    });

    it('detects unusable workspace paths after the path exists', function (): void {
        $app = workspaceableApp();
        $workspace = workspaceFor($app, ['name' => 'feature']);

        $snapshot = new ProbeSnapshot([
            'feature' => [
                'path_exists' => true,
                'path_usable' => false,
            ],
        ]);

        $drift = (new WorkspacesProbe)->diff($workspace, $snapshot);

        expect(issue($drift, 'workspace.path_unusable')?->kind)->toBe(DriftKind::Unverifiable);
    });

    it('detects workspace paths outside the parent app path', function (): void {
        $app = workspaceableApp(['path' => '/home/orbit/apps/docs']);
        $workspace = Workspace::factory()
            ->for($app, 'app')
            ->create([
                'name' => 'feature',
                'path' => '/home/orbit/other/feature',
            ]);

        $drift = (new WorkspacesProbe)->diff($workspace, new ProbeSnapshot([]));

        expect(issue($drift, 'workspace.path_outside_policy')?->kind)->toBe(DriftKind::Divergent);
    });

    it('detects generic workspace paths outside the app worktrees directory', function (): void {
        $app = workspaceableApp(['path' => '/home/orbit/apps/docs']);
        $workspace = Workspace::factory()
            ->for($app, 'app')
            ->create([
                'name' => 'feature',
                'path' => '/home/orbit/apps/docs/feature',
            ]);

        $drift = (new WorkspacesProbe)->diff($workspace, new ProbeSnapshot([]));

        expect(issue($drift, 'workspace.path_outside_policy')?->kind)->toBe(DriftKind::Divergent);
    });

    it('allows agent IDE workspace paths outside the parent app path', function (): void {
        $app = workspaceableApp(['path' => '/home/orbit/apps/docs']);
        $workspace = Workspace::factory()
            ->for($app, 'app')
            ->create([
                'name' => 'feature',
                'path' => '/home/orbit/.polyscope/clones/docs/feature',
                'agent_ide' => 'polyscope',
                'agent_ide_workspace_id' => 'poly-123',
            ]);

        $drift = (new WorkspacesProbe)->diff($workspace, new ProbeSnapshot([]));

        expect(issue($drift, 'workspace.path_outside_policy'))->toBeNull();
    });
});

describe('PHP runtime reality', function (): void {
    it('detects unavailable effective PHP-FPM runtimes', function (): void {
        $app = workspaceableApp(['php_version' => '8.5']);
        $workspace = workspaceFor($app, [
            'name' => 'feature',
            'php_version' => null,
        ]);

        $snapshot = new ProbeSnapshot([
            'feature' => [
                'path_exists' => true,
                'path_usable' => true,
                'php_fpm_available' => false,
            ],
        ]);

        $drift = (new WorkspacesProbe)->diff($workspace, $snapshot);

        expect(issue($drift, 'workspace.php_version_unavailable')?->kind)->toBe(DriftKind::Missing);
    });

    it('does not report PHP runtime drift before the workspace path exists', function (): void {
        $app = workspaceableApp();
        $workspace = workspaceFor($app, ['name' => 'feature']);

        $snapshot = new ProbeSnapshot([
            'feature' => [
                'path_exists' => false,
                'path_usable' => false,
                'php_fpm_available' => false,
            ],
        ]);

        $drift = (new WorkspacesProbe)->diff($workspace, $snapshot);

        expect(issue($drift, 'workspace.php_version_unavailable'))->toBeNull();
    });
});

describe('PHP-FPM configuration reality', function (): void {
    it('detects missing workspace PHP-FPM pool configuration', function (): void {
        $app = workspaceableApp();
        $workspace = workspaceFor($app, ['name' => 'feature']);

        $snapshot = new ProbeSnapshot([
            'feature' => convergedRuntimeSnapshot([
                'fpm_config_exists' => false,
                'fpm_config_matches' => false,
            ]),
        ]);

        $drift = (new WorkspacesProbe)->diff($workspace, $snapshot);

        expect(issue($drift, 'workspace.fpm_config_missing')?->kind)->toBe(DriftKind::Missing);
        expect(issue($drift, 'workspace.fpm_config_mismatch'))->toBeNull();
    });

    it('detects workspace PHP-FPM pool configuration mismatches', function (): void {
        $app = workspaceableApp();
        $workspace = workspaceFor($app, ['name' => 'feature']);

        $snapshot = new ProbeSnapshot([
            'feature' => convergedRuntimeSnapshot(['fpm_config_matches' => false]),
        ]);

        $drift = (new WorkspacesProbe)->diff($workspace, $snapshot);

        expect(issue($drift, 'workspace.fpm_config_mismatch')?->kind)->toBe(DriftKind::Divergent);
    });

    it('does not report workspace PHP-FPM pool drift before PHP-FPM is available', function (): void {
        $app = workspaceableApp();
        $workspace = workspaceFor($app, ['name' => 'feature']);

        $snapshot = new ProbeSnapshot([
            'feature' => convergedRuntimeSnapshot([
                'php_fpm_available' => false,
                'fpm_config_exists' => false,
                'fpm_config_matches' => false,
            ]),
        ]);

        $drift = (new WorkspacesProbe)->diff($workspace, $snapshot);

        expect(issue($drift, 'workspace.fpm_config_missing'))->toBeNull();
        expect(issue($drift, 'workspace.fpm_config_mismatch'))->toBeNull();
    });
});

describe('registry intent', function (): void {
    it('passes complete workspace records with eligible parent apps', function (): void {
        $app = workspaceableApp();
        $workspace = workspaceFor($app);

        $drift = $this->probe->diff($workspace, new ProbeSnapshot([]));

        expect($drift)->toBe([]);
    });

    it('detects incomplete workspace records', function (): void {
        $app = workspaceableApp();

        $id = DB::table('workspaces')->insertGetId([
            'app_id' => $app->id,
            'name' => 'feature',
            'path' => '',
            'lifecycle_status' => WorkspaceLifecycleStatus::Expected->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $workspace = Workspace::findOrFail($id);

        $drift = $this->probe->diff($workspace, new ProbeSnapshot([]));

        expect($drift)->toHaveCount(1);
        expect($drift[0]->family)->toBe('workspace');
        expect($drift[0]->key)->toBe('workspace.record_incomplete');
        expect($drift[0]->kind)->toBe(DriftKind::Missing);
    });

    it('accepts PHP version inherited from the parent app', function (): void {
        $app = workspaceableApp(['php_version' => '8.5']);
        $workspace = workspaceFor($app, ['php_version' => null]);

        $drift = $this->probe->diff($workspace, new ProbeSnapshot([]));
        $recordIssues = array_filter(
            $drift,
            fn (DriftEntry $entry): bool => $entry->key === 'workspace.record_incomplete',
        );

        expect($recordIssues)->toHaveCount(0);
    });

    it('requires an effective PHP version', function (): void {
        $app = workspaceableApp(['php_version' => '']);
        $workspace = workspaceFor($app, ['php_version' => null]);

        $drift = $this->probe->diff($workspace, new ProbeSnapshot([]));

        expect(issue($drift, 'workspace.record_incomplete')?->kind)->toBe(DriftKind::Missing);
    });
});

describe('parent app eligibility', function (): void {
    it('requires a parent app on an active app node', function (array $nodeState): void {
        $node = Node::factory()->create($nodeState);
        $app = App::factory()->for($node, 'node')->create();
        $workspace = workspaceFor($app);

        $drift = $this->probe->diff($workspace, new ProbeSnapshot([]));

        expect(issue($drift, 'workspace.parent_app_invalid')?->kind)->toBe(DriftKind::Divergent);
    })->with([
        'gateway parent node' => [['role' => 'gateway', 'status' => 'active']],
        'inactive app parent node' => [['role' => 'app', 'status' => 'inactive']],
    ]);
});

function issue(array $drift, string $key): ?DriftEntry
{
    return collect($drift)->first(fn (DriftEntry $entry): bool => $entry->key === $key);
}

function convergedRuntimeSnapshot(array $overrides = []): array
{
    return [
        'path_exists' => true,
        'path_usable' => true,
        'php_fpm_available' => true,
        'fpm_config_exists' => true,
        'fpm_config_matches' => true,
        ...$overrides,
    ];
}

function workspaceableApp(array $overrides = []): App
{
    $node = createTestAppHostNode();

    return App::factory()
        ->for($node, 'node')
        ->create($overrides);
}

function workspaceFor(App $app, array $overrides = []): Workspace
{
    $name = (string) ($overrides['name'] ?? 'feature');

    return Workspace::factory()
        ->for($app, 'app')
        ->create([
            'name' => $name,
            'path' => "{$app->path}/.worktrees/{$name}",
            ...$overrides,
        ]);
}

final class WorkspacesProbeRecordingRemoteShell implements RemoteShell
{
    /**
     * @var list<Node>
     */
    public array $nodes = [];

    /**
     * @var list<string>
     */
    public array $scripts = [];

    public function __construct(private readonly string $stdout) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->nodes[] = $node;
        $this->scripts[] = $script;

        return new RemoteShellResult(
            exitCode: 0,
            stdout: $this->stdout,
            stderr: '',
            durationMs: 1,
        );
    }
}
