<?php

declare(strict_types=1);

use App\Contracts\AgentIdeWorkspacePathResolver;
use App\Contracts\RemoteShell;
use App\Data\AgentIde\WorkspacePathResolution;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\WorkspaceLifecycleStatus;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['orbit.is_gateway' => true]);

    $gateway = Node::factory()->create([
        'name' => 'gateway',
        'role' => 'gateway',
    ]);

    $appNode = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'tld' => 'test',
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);

    App::factory()->create([
        'name' => 'docs',
        'node_id' => $appNode->id,
        'path' => '/srv/docs',
        'domain' => 'docs.test',
        'agent_ide_config' => ['adapter' => 'polyscope'],
    ]);

    App::factory()->create([
        'name' => 'api',
        'node_id' => $appNode->id,
        'path' => '/srv/api',
        'domain' => 'api.test',
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);

    app()->instance(RemoteShell::class, new WorkspaceSetupPathResolutionShell);
    app()->instance(AgentIdeWorkspacePathResolver::class, new WorkspaceSetupPathResolutionFakeResolver);
    WorkspaceSetupPathResolutionFakeResolver::$matches = [];
    WorkspaceSetupPathResolutionFakeResolver::$failures = [];
    WorkspaceSetupPathResolutionFakeResolver::$calls = [];
});

it('uses a registered workspace path from cwd without probing adapters', function (): void {
    Workspace::factory()->create([
        'app_id' => App::query()->where('name', 'docs')->value('id'),
        'name' => 'feature-docs',
        'path' => '/tmp/orbit-registered-workspace',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    $previousCwd = getcwd();
    workspaceSetupPathResolutionEnsureDirectory('/tmp/orbit-registered-workspace/nested');
    chdir('/tmp/orbit-registered-workspace/nested');

    try {
        $exitCode = Artisan::call('workspace:setup', ['--json' => true]);
    } finally {
        chdir((string) $previousCwd);
    }

    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['workspace'])->toBe('feature-docs')
        ->and(WorkspaceSetupPathResolutionFakeResolver::$calls)->toBe([]);
});

it('ignores a stale adapter-owned registered workspace when the adapter resolves the path to another app', function (): void {
    App::query()->whereIn('name', ['docs', 'api'])->update(['agent_ide_config' => ['adapter' => 'opencode']]);

    Workspace::factory()->create([
        'app_id' => App::query()->where('name', 'api')->value('id'),
        'name' => 'stale-api-workspace',
        'path' => '/tmp/orbit-stale-agent-workspace',
        'agent_ide' => 'opencode',
        'agent_ide_workspace_id' => 'stale-api-id',
        'lifecycle_status' => WorkspaceLifecycleStatus::Active,
    ]);

    WorkspaceSetupPathResolutionFakeResolver::$matches = [
        'opencode:docs' => new WorkspacePathResolution(
            workspaceName: 'feature-docs',
            appSlug: 'docs',
            path: '/tmp/orbit-stale-agent-workspace',
            adapterWorkspaceId: 'fresh-docs-id',
        ),
    ];

    $previousCwd = getcwd();
    workspaceSetupPathResolutionEnsureDirectory('/tmp/orbit-stale-agent-workspace');
    chdir('/tmp/orbit-stale-agent-workspace');

    try {
        $exitCode = Artisan::call('workspace:setup', ['--json' => true]);
    } finally {
        chdir((string) $previousCwd);
    }

    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
    $workspace = Workspace::query()->where('name', 'feature-docs')->first();

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['app'])->toBe('docs')
        ->and($payload['success']['data']['workspace'])->toBe('feature-docs')
        ->and($workspace)->not->toBeNull()
        ->and($workspace->agent_ide_workspace_id)->toBe('fresh-docs-id')
        ->and(WorkspaceSetupPathResolutionFakeResolver::$calls)->toContain(
            ['adapter' => 'opencode', 'app' => 'api', 'path' => workspaceSetupPathResolutionRealPath('/tmp/orbit-stale-agent-workspace')],
            ['adapter' => 'opencode', 'app' => 'docs', 'path' => workspaceSetupPathResolutionRealPath('/tmp/orbit-stale-agent-workspace')],
        );
});

it('fails before side effects when cwd is an app root', function (): void {
    $previousCwd = getcwd();
    workspaceSetupPathResolutionEnsureDirectory('/tmp/orbit-app-root');
    App::query()->where('name', 'docs')->update(['path' => '/tmp/orbit-app-root']);
    chdir('/tmp/orbit-app-root');

    try {
        $exitCode = Artisan::call('workspace:setup', ['--json' => true]);
    } finally {
        chdir((string) $previousCwd);
    }

    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('workspace.path_is_app_root')
        ->and($payload['error']['meta'])->toMatchArray([
            'app' => 'docs',
            'path' => workspaceSetupPathResolutionRealPath('/tmp/orbit-app-root'),
            'next_command' => 'orbit workspace:new',
        ]);
});

it('adopts an inside-app adapter match and records adapter metadata', function (): void {
    WorkspaceSetupPathResolutionFakeResolver::$matches = [
        'polyscope' => new WorkspacePathResolution(
            workspaceName: 'feature-docs',
            appSlug: 'docs',
            path: '/tmp/orbit-docs-app/.worktrees/feature-docs',
            adapterWorkspaceId: 'poly-123',
        ),
    ];

    $previousCwd = getcwd();
    workspaceSetupPathResolutionEnsureDirectory('/tmp/orbit-docs-app/.worktrees/feature-docs');
    App::query()->where('name', 'docs')->update(['path' => '/tmp/orbit-docs-app']);
    chdir('/tmp/orbit-docs-app/.worktrees/feature-docs');

    try {
        $exitCode = Artisan::call('workspace:setup', ['--json' => true]);
    } finally {
        chdir((string) $previousCwd);
    }

    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
    $workspace = Workspace::query()->where('name', 'feature-docs')->first();

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['action'])->toBe('adopted')
        ->and($workspace)->not->toBeNull()
        ->and($workspace->agent_ide)->toBe('polyscope')
        ->and($workspace->agent_ide_workspace_id)->toBe('poly-123')
        ->and(WorkspaceSetupPathResolutionFakeResolver::$calls)->toMatchArray([
            ['adapter' => 'polyscope', 'app' => 'docs', 'path' => workspaceSetupPathResolutionRealPath('/tmp/orbit-docs-app/.worktrees/feature-docs')],
        ]);
});

it('fails non-interactive setup when no adapter resolves an unregistered cwd', function (): void {
    $previousCwd = getcwd();
    workspaceSetupPathResolutionEnsureDirectory('/tmp/orbit-unregistered-workspace');
    chdir('/tmp/orbit-unregistered-workspace');

    try {
        $exitCode = Artisan::call('workspace:setup', ['--json' => true]);
    } finally {
        chdir((string) $previousCwd);
    }

    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta'])->toMatchArray([
            'field' => 'name',
            'reason' => 'missing_required_input',
        ]);
});

it('fails when multiple adapters resolve the cwd', function (): void {
    WorkspaceSetupPathResolutionFakeResolver::$matches = [
        'polyscope' => new WorkspacePathResolution('feature-docs', 'docs', '/tmp/orbit-ambiguous', 'poly-123'),
        'opencode' => new WorkspacePathResolution('feature-api', 'api', '/tmp/orbit-ambiguous', 'open-123'),
    ];

    $previousCwd = getcwd();
    workspaceSetupPathResolutionEnsureDirectory('/tmp/orbit-ambiguous');
    chdir('/tmp/orbit-ambiguous');

    try {
        $exitCode = Artisan::call('workspace:setup', ['--json' => true]);
    } finally {
        chdir((string) $previousCwd);
    }

    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['reason'])->toBe('adapter_ambiguous')
        ->and($payload['error']['meta']['adapters'])->toBe(['opencode', 'polyscope']);
});

it('translates adapter probe errors into the documented error code', function (): void {
    WorkspaceSetupPathResolutionFakeResolver::$failures = ['polyscope' => 'adapter_unreachable'];

    $previousCwd = getcwd();
    workspaceSetupPathResolutionEnsureDirectory('/tmp/orbit-probe-error/.worktrees/feature-docs');
    App::query()->where('name', 'docs')->update(['path' => '/tmp/orbit-probe-error']);
    chdir('/tmp/orbit-probe-error/.worktrees/feature-docs');

    try {
        $exitCode = Artisan::call('workspace:setup', ['--json' => true]);
    } finally {
        chdir((string) $previousCwd);
    }

    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('workspace.agent_ide_path_resolution_failed')
        ->and($payload['error']['meta'])->toMatchArray([
            'adapter' => 'polyscope',
            'path' => workspaceSetupPathResolutionRealPath('/tmp/orbit-probe-error/.worktrees/feature-docs'),
            'reason' => 'adapter_unreachable',
        ]);
});

it('rejects explicit identity mismatches against an adapter match', function (): void {
    WorkspaceSetupPathResolutionFakeResolver::$matches = [
        'polyscope' => new WorkspacePathResolution('feature-docs', 'docs', '/tmp/orbit-mismatch/.worktrees/feature-docs', 'poly-123'),
    ];

    $previousCwd = getcwd();
    workspaceSetupPathResolutionEnsureDirectory('/tmp/orbit-mismatch/.worktrees/feature-docs');
    App::query()->where('name', 'docs')->update(['path' => '/tmp/orbit-mismatch']);
    chdir('/tmp/orbit-mismatch/.worktrees/feature-docs');

    try {
        $exitCode = Artisan::call('workspace:setup', [
            'name' => 'other-feature',
            '--json' => true,
        ]);
    } finally {
        chdir((string) $previousCwd);
    }

    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta'])->toMatchArray([
            'field' => 'name',
            'reason' => 'adapter_mismatch',
        ]);
});

final class WorkspaceSetupPathResolutionShell implements RemoteShell
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

function workspaceSetupPathResolutionEnsureDirectory(string $path): void
{
    if (! is_dir($path)) {
        mkdir($path, recursive: true);
    }
}

function workspaceSetupPathResolutionRealPath(string $path): string
{
    return realpath($path) ?: $path;
}

final class WorkspaceSetupPathResolutionFakeResolver implements AgentIdeWorkspacePathResolver
{
    /** @var array<string, WorkspacePathResolution> */
    public static array $matches = [];

    /** @var array<string, string> */
    public static array $failures = [];

    /** @var list<array{adapter: string, app: string, path: string}> */
    public static array $calls = [];

    public function resolve(string $adapter, App $app, string $absolutePath): ?WorkspacePathResolution
    {
        self::$calls[] = ['adapter' => $adapter, 'app' => $app->name, 'path' => $absolutePath];

        if (isset(self::$failures[$adapter])) {
            throw new RuntimeException(self::$failures[$adapter]);
        }

        return self::$matches["{$adapter}:{$app->name}"]
            ?? self::$matches[$adapter]
            ?? null;
    }
}
