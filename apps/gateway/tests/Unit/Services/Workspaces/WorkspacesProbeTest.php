<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Workspaces;

use App\Contracts\RemoteShell;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\DriftKind;
use App\Enums\WorkspaceLifecycleStatus;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Tools\ToolScriptDispatcher;
use App\Services\Workspaces\WorkspacePlacement;
use App\Services\Workspaces\WorkspaceRuntimeContainerRenderer;
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

    it('defines only canonical persisted workspace lifecycle statuses', function (): void {
        expect(array_column(WorkspaceLifecycleStatus::cases(), 'value'))->toBe([
            'expected',
            'setup-pending',
        ]);
    });
});

describe('source path reality', function (): void {
    it('introspects workspace source path reality on the parent app node', function (): void {
        $app = workspaceableApp();
        $workspacePath = workspaceForAppPath($app).'/.worktrees/feature';
        $workspace = Workspace::factory()
            ->for($app, 'app')
            ->for($app->instances()->firstOrFail(), 'instance')
            ->create([
                'name' => 'feature',
                'path' => $workspacePath,
                'lifecycle_status' => WorkspaceLifecycleStatus::Expected,
            ]);
        $shell = new WorkspacesProbeRecordingRemoteShell("feature\t1\t1\t1\t1\t1\t1\t0\t0\t0\t\n");

        $snapshot = new WorkspacesProbe(
            scripts: new ToolScriptDispatcher(new WorkspacesProbeScriptExecutor($shell)),
        )->introspect($workspace);

        expect($snapshot->get('feature'))->toMatchArray([
            'path_exists' => true,
            'path_usable' => true,
            'system_user_exists' => true,
            'fs_permissions_ok' => true,
            'docker_available' => true,
            'runtime_image_available' => true,
            'runtime_image_probe_failed' => false,
            'container_exists' => false,
            'container_running' => false,
        ]);
        expect($shell->scripts[0])
            ->not
            ->toContain('php -r')
            ->and($shell->scripts[0])
            ->toContain('path_exists=')
            ->and($shell->scripts[0])
            ->toContain('docker container inspect')
            ->and($shell->scripts[0])
            ->toContain('printf \'%s\\t%s\\t%s\\t%s\\t%s\\t%s\\t%s\\t%s\\t%s\\t%s\\t%s\\n\'')
            ->and($shell->scripts[0])
            ->toContain($workspacePath);
        expect($shell->nodes[0]->is(workspaceableAppNode($app)))->toBeTrue();
    });

    it('does not remotely introspect production workspace registry drift', function (): void {
        $app = workspaceableApp(['environment' => 'production'], role: 'app-prod');
        $workspace = workspaceFor($app, ['name' => 'feature']);
        $shell = new WorkspacesProbeRecordingRemoteShell("feature\t1\t1\t1\t1\t1\t1\t0\t0\t0\t\n");

        $snapshot = new WorkspacesProbe(
            scripts: new ToolScriptDispatcher(new WorkspacesProbeScriptExecutor($shell)),
        )->introspect($workspace);

        expect($snapshot->isEmpty())
            ->toBeTrue()
            ->and($shell->scripts)
            ->toBe([])
            ->and($shell->nodes)
            ->toBe([]);
    });

    it('does not contain host-lane php eval probe snippets', function (): void {
        expect(file_get_contents(app_path('Services/Workspaces/WorkspacesProbe.php')))->not->toContain('php -r');
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

        $drift = new WorkspacesProbe()->diff($workspace, $snapshot);

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

        $drift = new WorkspacesProbe()->diff($workspace, $snapshot);

        expect(issue($drift, 'workspace.path_unusable')?->kind)->toBe(DriftKind::Unverifiable);
    });

    it('allows generic workspace paths outside the parent app path', function (): void {
        $app = workspaceableApp(['path' => '/home/orbit/apps/docs']);
        $workspace = Workspace::factory()
            ->for($app, 'app')
            ->create([
                'name' => 'feature',
                'path' => '/home/orbit/other/feature',
            ]);

        $drift = new WorkspacesProbe()->diff($workspace, new ProbeSnapshot([]));

        expect(issue($drift, 'workspace.path_outside_policy'))->toBeNull();
    });

    it('allows generic workspace paths outside the app worktrees directory', function (): void {
        $app = workspaceableApp(['path' => '/home/orbit/apps/docs']);
        $workspace = Workspace::factory()
            ->for($app, 'app')
            ->create([
                'name' => 'feature',
                'path' => '/home/orbit/apps/docs/feature',
            ]);

        $drift = new WorkspacesProbe()->diff($workspace, new ProbeSnapshot([]));

        expect(issue($drift, 'workspace.path_outside_policy'))->toBeNull();
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

        $drift = new WorkspacesProbe()->diff($workspace, new ProbeSnapshot([]));

        expect(issue($drift, 'workspace.path_outside_policy'))->toBeNull();
    });

    it('detects workspace paths that equal the parent app root', function (): void {
        $app = workspaceableApp(['path' => '/home/orbit/apps/docs']);
        $workspace = Workspace::factory()
            ->for($app, 'app')
            ->create([
                'name' => 'feature',
                'path' => '/home/orbit/apps/docs',
            ]);

        $drift = new WorkspacesProbe()->diff($workspace, new ProbeSnapshot([]));

        expect(issue($drift, 'workspace.path_outside_policy')?->kind)->toBe(DriftKind::Divergent);
    });
});

describe('PHP runtime reality', function (): void {
    it('detects unsupported PHP versions for Docker-first workspaces', function (): void {
        $app = workspaceableApp(['php_version' => '7.4']);
        $workspace = workspaceFor($app, [
            'name' => 'feature',
            'php_version' => null,
            'lifecycle_status' => WorkspaceLifecycleStatus::Expected,
        ]);

        $snapshot = new ProbeSnapshot([
            'feature' => [
                'path_exists' => true,
                'path_usable' => true,
            ],
        ]);

        $drift = new WorkspacesProbe()->diff($workspace, $snapshot);

        expect(issue($drift, 'workspace.php_version_unavailable')?->kind)->toBe(DriftKind::Missing);
    });

    it('does not report PHP version unavailable when path is missing', function (): void {
        $app = workspaceableApp(['php_version' => '8.5']);
        $workspace = workspaceFor($app, [
            'name' => 'feature',
            'lifecycle_status' => WorkspaceLifecycleStatus::Expected,
        ]);

        $snapshot = new ProbeSnapshot([
            'feature' => [
                'path_exists' => false,
                'path_usable' => false,
            ],
        ]);

        $drift = new WorkspacesProbe()->diff($workspace, $snapshot);

        expect(issue($drift, 'workspace.php_version_unavailable'))->toBeNull();
    });

    it('does not report PHP runtime drift before the workspace path exists', function (): void {
        $app = workspaceableApp();
        $workspace = workspaceFor($app, [
            'name' => 'feature',
            'lifecycle_status' => WorkspaceLifecycleStatus::Expected,
        ]);

        $snapshot = new ProbeSnapshot([
            'feature' => [
                'path_exists' => false,
                'path_usable' => false,
            ],
        ]);

        $drift = new WorkspacesProbe()->diff($workspace, $snapshot);

        expect(issue($drift, 'workspace.php_version_unavailable'))->toBeNull();
    });

    it('does not report PHP runtime drift while workspace setup is pending', function (): void {
        $app = workspaceableApp(['php_version' => '7.4']);
        $workspace = workspaceFor($app, [
            'name' => 'feature',
            'php_version' => null,
            'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
        ]);

        $drift = new WorkspacesProbe()->diff($workspace, new ProbeSnapshot([
            'feature' => convergedRuntimeSnapshot([
                'docker_available' => true,
                'runtime_image_available' => false,
                'runtime_image_probe_failed' => false,
                'container_exists' => false,
            ]),
        ]));

        expect(issue($drift, 'workspace.php_version_unavailable'))->toBeNull();
    });

    it('hands missing PHP workspace runtime units to process doctor without duplicate workspace issues', function (): void {
        $app = workspaceableApp();
        $workspace = workspaceFor($app, [
            'name' => 'feature',
            'lifecycle_status' => WorkspaceLifecycleStatus::Expected,
        ]);

        $drift = new WorkspacesProbe()->diff($workspace, new ProbeSnapshot([
            'feature' => convergedRuntimeSnapshot([
                'docker_available' => true,
                'runtime_image_available' => true,
                'runtime_image_probe_failed' => false,
                'container_exists' => false,
                'container_running' => false,
                'container_name' => 'orbit-ws-docs-feature',
            ]),
        ]));

        expect(issue($drift, 'workspace.runtime_container_missing'))->toBeNull();
    });

    it('hands stopped PHP workspace runtime units to process doctor without duplicate workspace issues', function (): void {
        $app = workspaceableApp();
        $workspace = workspaceFor($app, [
            'name' => 'feature',
            'lifecycle_status' => WorkspaceLifecycleStatus::Expected,
        ]);

        $drift = new WorkspacesProbe()->diff($workspace, new ProbeSnapshot([
            'feature' => convergedRuntimeSnapshot([
                'docker_available' => true,
                'runtime_image_available' => true,
                'runtime_image_probe_failed' => false,
                'container_exists' => true,
                'container_running' => false,
                'container_name' => 'orbit-ws-docs-feature',
            ]),
        ]));

        expect(issue($drift, 'workspace.runtime_container_stopped'))->toBeNull();
    });

    it('hands mismatched PHP workspace runtime units to process doctor without duplicate workspace issues', function (): void {
        $app = workspaceableApp();
        $workspace = workspaceFor($app, [
            'name' => 'feature',
            'lifecycle_status' => WorkspaceLifecycleStatus::Expected,
        ]);
        $expectedHash = app(WorkspaceRuntimeContainerRenderer::class)->render($workspace)->specHash();

        $drift = new WorkspacesProbe()->diff($workspace, new ProbeSnapshot([
            'feature' => convergedRuntimeSnapshot([
                'docker_available' => true,
                'runtime_image_available' => true,
                'runtime_image_probe_failed' => false,
                'container_exists' => true,
                'container_running' => true,
                'container_spec_hash' => 'stale',
                'container_expected_hash' => $expectedHash,
                'container_name' => 'orbit-ws-docs-feature',
            ]),
        ]));

        expect(issue($drift, 'workspace.runtime_container_mismatch'))->toBeNull();
    });

    it('does not report PHP workspace runtime container drift before the image is available', function (): void {
        $app = workspaceableApp();
        $workspace = workspaceFor($app, [
            'name' => 'feature',
            'lifecycle_status' => WorkspaceLifecycleStatus::Expected,
        ]);

        $drift = new WorkspacesProbe()->diff($workspace, new ProbeSnapshot([
            'feature' => convergedRuntimeSnapshot([
                'docker_available' => true,
                'runtime_image_available' => false,
                'runtime_image_probe_failed' => false,
                'container_exists' => false,
            ]),
        ]));

        expect(issue($drift, 'workspace.runtime_container_missing'))->toBeNull();
    });

    it('does not require PHP workspace runtime containers while workspace setup is pending', function (): void {
        $app = workspaceableApp();
        $workspace = workspaceFor($app, [
            'name' => 'feature',
            'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
        ]);

        $drift = new WorkspacesProbe()->diff($workspace, new ProbeSnapshot([
            'feature' => convergedRuntimeSnapshot([
                'docker_available' => true,
                'runtime_image_available' => true,
                'runtime_image_probe_failed' => false,
                'container_exists' => false,
                'container_running' => false,
                'container_name' => 'orbit-ws-docs-feature',
            ]),
        ]));

        expect(issue($drift, 'workspace.runtime_container_missing'))->toBeNull();
    });
});

describe('workspace security reality', function (): void {
    it('detects development workspace runtime isolation drift', function (): void {
        $app = workspaceableApp(['runtime' => AppRuntimeKind::Static]);
        $workspace = workspaceFor($app, ['name' => 'feature']);

        $snapshot = new ProbeSnapshot([
            'feature' => convergedRuntimeSnapshot([
                'system_user_exists' => false,
                'fs_permissions_ok' => false,
            ]),
        ]);

        $drift = new WorkspacesProbe()->diff($workspace, $snapshot);

        expect(issue($drift, 'workspace.security.system_user')?->kind)
            ->toBe(DriftKind::Missing)
            ->and(issue($drift, 'workspace.security.fs_permissions')?->kind)
            ->toBe(DriftKind::Divergent);
    });

    it('does not report host runtime isolation drift for Docker-first PHP workspaces', function (): void {
        $app = workspaceableApp(['runtime' => AppRuntimeKind::Php]);
        $workspace = workspaceFor($app, ['name' => 'feature']);

        $snapshot = new ProbeSnapshot([
            'feature' => convergedRuntimeSnapshot([
                'system_user_exists' => false,
                'fs_permissions_ok' => false,
            ]),
        ]);

        $drift = new WorkspacesProbe()->diff($workspace, $snapshot);

        expect(issue($drift, 'workspace.security.system_user'))
            ->toBeNull()
            ->and(issue($drift, 'workspace.security.fs_permissions'))
            ->toBeNull();
    });

    it('flags workspaces that belong to production app nodes', function (): void {
        $app = workspaceableApp(['environment' => 'production'], role: 'app-prod');
        $workspace = workspaceFor($app, ['name' => 'feature']);

        $drift = new WorkspacesProbe()->diff($workspace, new ProbeSnapshot([]));

        expect(issue($drift, 'workspace.unsupported_for_production')?->kind)->toBe(DriftKind::Divergent);
    });
});

describe('docker-first runtime (no FPM drift for PHP workspaces)', function (): void {
    it('does not report FPM config drift for PHP workspaces', function (): void {
        $app = workspaceableApp();
        $workspace = workspaceFor($app, ['name' => 'feature']);

        $snapshot = new ProbeSnapshot([
            'feature' => convergedRuntimeSnapshot(),
        ]);

        $drift = new WorkspacesProbe()->diff($workspace, $snapshot);

        expect(issue($drift, 'workspace.fpm_config_missing'))->toBeNull();
        expect(issue($drift, 'workspace.fpm_config_mismatch'))->toBeNull();
    });

    it('does not report FPM security drift for PHP workspaces', function (): void {
        $app = workspaceableApp();
        $workspace = workspaceFor($app, ['name' => 'feature']);

        $snapshot = new ProbeSnapshot([
            'feature' => convergedRuntimeSnapshot([
                'system_user_exists' => false,
                'fs_permissions_ok' => false,
            ]),
        ]);

        $drift = new WorkspacesProbe()->diff($workspace, $snapshot);

        expect(issue($drift, 'workspace.security.fpm_pool_isolation'))->toBeNull();
        expect(issue($drift, 'workspace.security.fpm_systemd_hardening'))->toBeNull();
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
            'instance_id' => $app->instances()->value('id'),
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

    it('requires an app instance identity', function (): void {
        $app = workspaceableApp();
        $workspace = workspaceFor($app);
        $workspace->setAttribute('instance_id', null);
        $workspace->setRelation('instance', null);

        $drift = $this->probe->diff($workspace, new ProbeSnapshot([]));

        expect(issue($drift, 'workspace.record_incomplete')?->kind)
            ->toBe(DriftKind::Missing)
            ->and(issue($drift, 'workspace.app_instance_invalid')?->kind)
            ->toBe(DriftKind::Divergent);
    });
});

describe('app instance eligibility', function (): void {
    it('requires a selected app instance that belongs to the parent app', function (): void {
        $app = workspaceableApp();
        $otherApp = workspaceableApp();
        $workspace = workspaceFor($app, [
            'instance_id' => $otherApp->instances()->value('id'),
        ]);

        $drift = $this->probe->diff($workspace, new ProbeSnapshot([]));

        expect(issue($drift, 'workspace.app_instance_invalid')?->kind)
            ->toBe(DriftKind::Divergent)
            ->and(issue($drift, 'workspace.parent_project_invalid'))
            ->toBeNull();
    });

    it('requires the selected app instance to resolve to an active app node', function (callable $createNode): void {
        $node = $createNode();
        assert($node instanceof Node);
        /** @var App $app */
        $app = App::factory()->placedOn($node)->create();
        $workspace = workspaceFor($app);

        $drift = $this->probe->diff($workspace, new ProbeSnapshot([]));

        expect(issue($drift, 'workspace.app_instance_invalid')?->kind)
            ->toBe(DriftKind::Divergent)
            ->and(issue($drift, 'workspace.parent_project_invalid'))
            ->toBeNull();
    })->with([
        'gateway instance node' => [fn (): Node => Node::factory()->gateway()->create(['status' => 'active'])],
        'inactive app instance node' => [fn (): Node => Node::factory()->appDev()->create(['status' => 'inactive'])],
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
        'system_user_exists' => true,
        'fs_permissions_ok' => true,
        'docker_available' => true,
        'runtime_image_available' => true,
        'runtime_image_probe_failed' => false,
        'container_exists' => true,
        'container_running' => true,
        'container_spec_hash' => '',
        'container_expected_hash' => '',
        'container_name' => '',
        ...$overrides,
    ];
}

function workspaceableApp(array $overrides = [], string $role = 'app-dev'): App
{
    $node = createTestAppHostNode(role: $role);

    $path = isset($overrides['path']) && is_string($overrides['path']) ? $overrides['path'] : null;
    $documentRoot = isset($overrides['document_root']) && is_string($overrides['document_root'])
        ? $overrides['document_root']
        : 'public';
    $domain = isset($overrides['domain']) && is_string($overrides['domain']) ? $overrides['domain'] : null;
    unset(
        $overrides['path'],
        $overrides['document_root'],
        $overrides['domain'],
        $overrides['environment'],
        $overrides['node_id'],
    );

    /** @var App $app */
    $app = App::factory()
        ->placedOn($node, 'development', $path, $documentRoot, $domain)
        ->create($overrides);

    return $app;
}

function workspaceableAppNode(App $app): Node
{
    $node = app(WorkspacePlacement::class)->runtimeNode($app, null);

    if (! $node instanceof Node) {
        throw new \RuntimeException('Placed app is missing a serving node.');
    }

    return $node;
}

function workspaceForAppPath(App $app): string
{
    return '/home/orbit/apps/'.$app->name;
}

function workspaceFor(App $app, array $overrides = []): Workspace
{
    $name = (string) ($overrides['name'] ?? 'feature');

    return Workspace::factory()
        ->for($app, 'app')
        ->for($app->instances()->firstOrFail(), 'instance')
        ->create([
            'name' => $name,
            'path' => workspaceForAppPath($app).'/.worktrees/'.$name,
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

    /**
     * @var list<array<string, mixed>>
     */
    public array $options = [];

    public function __construct(
        private readonly string $stdout,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->nodes[] = $node;
        $this->scripts[] = $script;
        $this->options[] = $options;

        return new RemoteShellResult(
            exitCode: 0,
            stdout: $this->stdout,
            stderr: '',
            durationMs: 1,
        );
    }
}

final readonly class WorkspacesProbeScriptExecutor implements RunsInternalCommands
{
    public function __construct(
        private RemoteShell $shell,
    ) {}

    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        $payload = json_decode((string) $transportOptions['input'], true, flags: JSON_THROW_ON_ERROR);
        $result = $this->shell->run($node, (string) $payload['script'], $transportOptions);

        return new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'success' => ['data' => [
                    'exit_code' => $result->exitCode,
                    'stdout' => $result->stdout,
                    'stderr' => $result->stderr,
                    'duration_ms' => $result->durationMs,
                ]],
            ], JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: $result->durationMs,
        );
    }
}
