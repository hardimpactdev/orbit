<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Enums\ProcessCrashNotification;
use App\Enums\ProcessEventType;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeTool;
use App\Models\Process;
use App\Models\ProcessEvent;
use App\Models\Workspace;
use App\Services\Nodes\Access\NodePermissionNormalizer;
use App\Services\Nodes\Access\NodePermissionPresets;
use App\Services\Nodes\Access\NodePermissionRegistry;
use App\Services\Tools\LegacyOpenCodeRuntimeCleanup;
use App\Services\Tools\LegacyPolyscopeRuntimeCleanup;
use App\Services\Tools\ToolCatalog;
use App\Services\Workspaces\WorktreeWorkspaceDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('proves removed ADE, OpenCode, and PolyScope public surfaces are absent', function (): void {
    $routes = collect(app('router')->getRoutes())->map(
        static fn ($route): string => implode('|', $route->methods()).' '.$route->uri(),
    );

    expect($routes->contains(fn (string $route): bool => str_contains($route, 'agent-ide')))
        ->toBeFalse()
        ->and($routes->contains(fn (string $route): bool => str_contains($route, 'instances/prune')))
        ->toBeFalse()
        ->and($routes->contains(fn (string $route): bool => str_contains($route, 'events/process')))
        ->toBeFalse();

    $this->getJson('/api/agent-ide/adapters')->assertNotFound();
    $this->postJson('/api/agent-ide/message', [])->assertNotFound();
    $this->postJson('/api/instances/demo.main/agent-ide', [])->assertNotFound();
    $this->postJson('/api/nodes/demo/agent-ide', [])->assertNotFound();
    $this->postJson('/api/instances/prune', [])->assertNotFound();
    $this->postJson('/api/events/process', [])->assertNotFound();

    $registry = app(NodePermissionRegistry::class);
    foreach ([
        'agent-ide:*',
        'agent-ide:message',
        'instance:agent',
        'node:agent',
        'instance:prune',
    ] as $permission) {
        expect($registry->all())->not->toContain($permission);
    }

    $catalog = app(ToolCatalog::class);
    expect($catalog->supports('opencode-cli'))
        ->toBeFalse()
        ->and($catalog->supports('polyscope-server'))
        ->toBeFalse()
        ->and($catalog->supports('opencode'))
        ->toBeFalse()
        ->and($catalog->supports('opencode-server'))
        ->toBeFalse();

    expect(class_exists(\App\Tools\OpenCodeCliTool::class))
        ->toBeFalse()
        ->and(class_exists(\App\Tools\PolyscopeServerTool::class))
        ->toBeFalse()
        ->and(class_exists(\App\Services\Workspaces\OpenCodeWorkspaceDriver::class))
        ->toBeFalse()
        ->and(class_exists(\App\Services\Workspaces\PolyscopeWorkspaceDriver::class))
        ->toBeFalse()
        ->and(class_exists(\App\Services\AgentIde\AgentIdeAdapterRegistry::class))
        ->toBeFalse()
        ->and(class_exists(\App\Services\Processes\ProcessEventNotifierRenderer::class))
        ->toBeFalse()
        ->and(ProcessCrashNotification::tryFrom('agent_ide'))
        ->toBeNull();

    expect(
        app()->bound(WorktreeWorkspaceDriver::class)
        || app()->make(WorktreeWorkspaceDriver::class) instanceof WorktreeWorkspaceDriver,
    )->toBeTrue();
});

it('clears legacy ADE storage, permissions, and tool intent while preserving generic workspaces and process history', function (): void {
    $node = Node::factory()->create();
    $app = App::factory()->create();
    $instance = Instance::factory()->for($app)->create([
        'name' => 'development',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '/home/orbit/apps/'.$app->name,
            document_root: 'public',
            domain: null,
        ),
    ]);
    $workspace = Workspace::factory()->create([
        'app_id' => $app->id,
        'instance_id' => $instance->id,
        'name' => 'feature-ade-cleanup',
        'path' => '/tmp/feature-ade-cleanup',
    ]);
    DB::table('nodes')
        ->where('id', $node->id)
        ->update([
            'agent_ide_config' => json_encode(['adapter' => 'opencode']),
        ]);
    DB::table('apps')
        ->where('id', $app->id)
        ->update([
            'agent_ide_config' => json_encode(['adapter' => 'polyscope']),
        ]);
    DB::table('instances')
        ->where('id', $instance->id)
        ->update([
            'agent_ide_config' => json_encode(['adapter' => 'opencode']),
        ]);
    DB::table('workspaces')
        ->where('id', $workspace->id)
        ->update([
            'agent_ide' => 'opencode',
            'agent_ide_workspace_id' => 'ws-123',
        ]);
    $process = Process::factory()
        ->forOwner($app, $node)
        ->create([
            'name' => 'history-process',
            'crash_notification' => ProcessCrashNotification::None,
        ]);
    DB::table('processes')
        ->where('id', $process->id)
        ->update(['crash_notification' => 'agent_ide']);
    $process->refresh();
    ProcessEvent::factory()->create([
        'process_id' => $process->id,
        'app_id' => $app->id,
        'node_id' => $node->id,
        'event' => ProcessEventType::Crashed,
        'unit_name' => 'history-process',
    ]);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'opencode-cli',
        'expected_state' => 'installed',
    ]);
    Process::factory()
        ->forOwner($node)
        ->create([
            'name' => 'opencode-server',
            'tool' => 'opencode-cli',
            'crash_notification' => ProcessCrashNotification::None,
        ]);
    Process::factory()
        ->forOwner($node)
        ->create([
            'name' => 'polyscope-server',
            'tool' => 'polyscope-server',
            'crash_notification' => ProcessCrashNotification::None,
        ]);
    NodeAccess::query()->create([
        'consumer_node_id' => $node->id,
        'serving_node_id' => $node->id,
        'permissions' => [
            'agent-ide:message',
            'instance:agent',
            'node:agent',
            'instance:prune',
            'app:agent',
            'app:prune',
            'instance:read',
        ],
        'custom_permissions' => ['agent-ide:*', 'instance:show'],
    ]);

    $migrationPath = collect(File::files(database_path('migrations')))
        ->map(static fn (\SplFileInfo $file): string => $file->getPathname())
        ->first(static fn (string $path): bool => str_contains(basename($path), 'clear_legacy_ade_opencode_polyscope'));

    expect($migrationPath)->not->toBeNull('Release A migration clear_legacy_ade_opencode_polyscope must exist');

    // RefreshDatabase already applied the migration before fixtures were seeded.
    // Re-run the cleanup body against the seeded legacy residue (same pattern as
    // OpenCodeCliToolBackfillTest). The historical migration is byte-stable and
    // owns the pre-cutover app_instances table name; present that schema only for
    // this re-run. The 2026-08-05 cutover exclusively renames to instances.
    expect(Schema::hasTable('instances'))->toBeTrue()->and(Schema::hasTable('app_instances'))->toBeFalse();

    Schema::rename('instances', 'app_instances');

    try {
        $migration = require $migrationPath;
        $migration->up();
    } finally {
        if (Schema::hasTable('app_instances') && ! Schema::hasTable('instances')) {
            Schema::rename('app_instances', 'instances');
        }
    }

    $node->refresh();
    $app->refresh();
    $instance->refresh();
    $workspace->refresh();
    $process->refresh();

    expect(DB::table('nodes')->where('id', $node->id)->value('agent_ide_config'))
        ->toBeNull()
        ->and(DB::table('apps')->where('id', $app->id)->value('agent_ide_config'))
        ->toBeNull()
        ->and(DB::table('instances')->where('id', $instance->id)->value('agent_ide_config'))
        ->toBeNull()
        ->and(DB::table('workspaces')->where('id', $workspace->id)->value('agent_ide'))
        ->toBeNull()
        ->and(DB::table('workspaces')->where('id', $workspace->id)->value('agent_ide_workspace_id'))
        ->toBeNull()
        ->and($workspace->name)
        ->toBe('feature-ade-cleanup')
        ->and($workspace->path)
        ->toBe('/tmp/feature-ade-cleanup')
        ->and($process->crash_notification)
        ->toBe(ProcessCrashNotification::None)
        ->and(
            ProcessEvent::query()
                ->where('process_id', $process->id)
                ->where('event', ProcessEventType::Crashed)
                ->count(),
        )
        ->toBe(1)
        ->and(
            NodeTool::query()
                ->whereIn('name', ['opencode-cli', 'opencode', 'opencode-server', 'polyscope-server'])
                ->count(),
        )
        ->toBe(0)
        ->and(Process::query()->whereIn('name', ['opencode-server', 'polyscope-server'])->count())
        ->toBe(0)
        ->and(
            Process::query()
                ->whereIn('tool', ['opencode-cli', 'opencode', 'opencode-server', 'polyscope-server'])
                ->count(),
        )
        ->toBe(0);

    $access = NodeAccess::query()->where('serving_node_id', $node->id)->firstOrFail();
    expect($access->permissions)
        ->not->toContain('agent-ide:message')->and($access->permissions)
        ->not->toContain('instance:agent')->and($access->permissions)
        ->not->toContain('node:agent')->and($access->permissions)
        ->not->toContain('instance:prune')->and($access->permissions)
        ->not->toContain('app:agent')->and($access->permissions)
        ->not->toContain('app:prune')->and($access->permissions)->toContain(
            'instance:read',
        )->and($access->custom_permissions)
        ->not->toContain('agent-ide:*')->and($access->custom_permissions)->toContain('instance:show');

    foreach ([
        'nodes.agent_ide_config',
        'apps.agent_ide_config',
        'instances.agent_ide_config',
        'workspaces.agent_ide',
        'workspaces.agent_ide_workspace_id',
        'processes.crash_notification',
    ] as $column) {
        [$table, $name] = explode('.', $column);
        expect(Schema::hasColumn($table, $name))->toBeTrue();
    }
});

it('does not expand removed predecessor grants into current permissions', function (): void {
    $registry = app(NodePermissionRegistry::class);
    $normalizer = app(NodePermissionNormalizer::class);

    foreach (['app:agent', 'app:prune'] as $token) {
        expect($registry->isKnown($token))->toBeFalse();
        expect(fn () => $normalizer->normalize([$token]))
            ->toThrow(InvalidArgumentException::class, "Unknown permission [{$token}].");
    }

    expect($normalizer->normalize(['instance:read'])->permissions)
        ->toBe(['instance:read']);
});

it('keeps permission presets free of removed ADE grants', function (): void {
    $presets = app(NodePermissionPresets::class);
    $removed = [
        'agent-ide:*',
        'agent-ide:message',
        'instance:agent',
        'node:agent',
        'instance:prune',
    ];

    foreach ($presets->names() as $name) {
        $permissions = $presets->permissions($name);
        foreach ($removed as $permission) {
            expect($permissions)->not->toContain($permission);
        }
    }
});

it('exposes removal-only legacy cleanup for Orbit-managed OpenCode and PolyScope residue', function (): void {
    $openCode = app(LegacyOpenCodeRuntimeCleanup::class);
    $polyscope = app(LegacyPolyscopeRuntimeCleanup::class);

    expect($openCode->applies('opencode-cli'))
        ->toBeTrue()
        ->and($openCode->applies('opencode'))
        ->toBeTrue()
        ->and($openCode->applies('opencode-server'))
        ->toBeTrue()
        ->and($openCode->applies('hermes'))
        ->toBeFalse()
        ->and($polyscope->applies('polyscope-server'))
        ->toBeTrue()
        ->and($polyscope->applies('hermes'))
        ->toBeFalse();

    $openCodeScript = $openCode->cleanupScript();
    $polyscopeScript = $polyscope->cleanupScript();

    expect($openCodeScript)
        ->toContain('opencode-server.service')
        ->and($openCodeScript)
        ->toContain('/home/agent/.opencode')
        ->and($openCodeScript)
        ->toMatch('/exit 1/')
        ->and($polyscopeScript)
        ->toContain('polyscope-server.service')
        ->and($polyscopeScript)
        ->toContain('.local/bin/polyscope-server')
        ->and($polyscopeScript)
        ->toMatch('/exit 1/');
});

it('does not target arbitrary personal OpenCode install paths outside Orbit ownership', function (): void {
    $script = app(LegacyOpenCodeRuntimeCleanup::class)->cleanupScript();

    expect($script)
        ->not->toContain('find /')->and($script)->toContain('/home/agent/.opencode')->and($script)
        ->not->toContain('rm -rf $HOME/.opencode');
});

it('closes orbit skill active ADE residue regressions', function (): void {
    $repo = rtrim((string) repo_path(), '/');

    foreach ([
        '/.agents/skills/orbit/SKILL.md',
        '/.agents/skills/orbit/references/process.md',
        '/.agents/skills/orbit/references/app.md',
    ] as $relative) {
        $contents = (string) file_get_contents($repo.$relative);

        expect($contents)
            ->not->toMatch('/agent_ide|agent-ide|Agent IDE|opencode|polyscope/i');
    }
});

it('closes e2e source active ADE residue regressions', function (): void {
    $hits = ade_residue_php_paths([
        'apps/e2e/tests',
        'apps/e2e/app',
    ]);

    expect($hits)
        ->toBeEmpty('Unexpected ADE residue in e2e source: '.implode(', ', $hits));
});

it('closes sdk process request active ADE residue regressions', function (): void {
    $repo = rtrim((string) repo_path(), '/');

    foreach ([
        '/packages/sdk/tests/Unit/Requests/Processes/AddProcessRequestTest.php',
        '/packages/sdk/tests/Unit/Requests/Processes/UpdateProcessRequestTest.php',
    ] as $relative) {
        $contents = (string) file_get_contents($repo.$relative);

        expect($contents)
            ->not
            ->toContain('agent_ide')
            ->and($contents)
            ->toContain("'none'");
    }
});

it('closes product docs active ADE residue regressions', function (): void {
    $hits = ade_residue_docs_paths();

    expect($hits)
        ->toBeEmpty('Unexpected active ADE residue in product docs: '.implode(', ', $hits));
});

it('keeps ORBIT_POLYSCOPE_ secret-redaction prefix without restoring product support', function (): void {
    $metadata = (string) file_get_contents(repo_path('apps/gateway/app/Services/RemoteShell/RemoteShellMetadata.php'));

    expect($metadata)
        ->toContain('ORBIT_POLYSCOPE_')
        ->and(class_exists(\App\Tools\PolyscopeServerTool::class))
        ->toBeFalse();
});

/**
 * @param  list<string>  $relativeRoots
 * @return list<string>
 */
function ade_residue_php_paths(array $relativeRoots): array
{
    $repo = rtrim((string) repo_path(), '/');
    $hits = [];

    foreach ($relativeRoots as $relativeRoot) {
        $root = $repo.'/'.$relativeRoot;

        if (! is_dir($root)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getPathname(), '.php')) {
                continue;
            }

            $path = $file->getPathname();

            if (str_contains($path, '/vendor/')) {
                continue;
            }

            $contents = (string) file_get_contents($path);

            if (preg_match('/opencode|polyscope|agent_ide|agent-ide|AgentIde/i', $contents) === 1) {
                $hits[] = str_replace($repo.'/', '', $path);
            }
        }
    }

    return $hits;
}

/**
 * @return list<string>
 */
function ade_residue_docs_paths(): array
{
    $repo = rtrim((string) repo_path(), '/');
    $hits = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo.'/apps/docs/content'));

    foreach ($iterator as $file) {
        if (! $file->isFile()) {
            continue;
        }

        $path = $file->getPathname();

        if (! str_ends_with($path, '.md') && ! str_ends_with($path, '.json')) {
            continue;
        }

        $relative = str_replace($repo.'/', '', $path);
        $contents = (string) file_get_contents($path);

        if (preg_match('/opencode|polyscope|agent_ide|agent-ide|AgentIde/i', $contents) !== 1) {
            continue;
        }

        if (str_contains($relative, 'domains/3_tool/4_tool-remove/tool-remove.md')) {
            continue;
        }

        if (
            str_contains($relative, 'domains/7_process/README.md')
            && str_contains($contents, 'agent_ide')
            && str_contains($contents, 'Release A')
        ) {
            continue;
        }

        $hits[] = $relative;
    }

    return $hits;
}
