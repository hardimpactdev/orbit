<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppInstanceDriver;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\WorkspaceLifecycleStatus;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const WORKSPACE_STORE_CALLER_WG_IP = '10.6.0.99';

beforeEach(function (): void {
    createTestGatewayNode([
        'name' => 'gateway',
        'host' => 'gateway',
        'orbit_path' => '/home/gateway/orbit',
        'status' => 'active',
        'wireguard_address' => WORKSPACE_STORE_CALLER_WG_IP,
    ]);

    $appNode = createTestAppHostNode([
        'name' => 'demo-node',
        'wireguard_address' => '10.6.0.7',
    ]);

    $app = Project::factory()->for($appNode, 'node')->create([
        'name' => 'demo',
        'domain' => 'demo.beast',
        'path' => '/home/nckrtl/apps/demo',
        'php_version' => '8.5',
        'runtime' => AppRuntimeKind::Php,
    ]);
    AppInstance::factory()->for($app)->create([
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $appNode->id,
            path: $app->path,
            document_root: $app->document_root,
            domain: $app->domain,
        ),
    ]);

    app()->instance(RemoteShell::class, new WorkspaceStoreTestShell);
});

it('creates a workspace for an authorized gateway caller', function (): void {
    $response = $this->call(
        'POST',
        '/api/workspaces',
        [
            'name' => 'feature-a',
            'instance' => 'demo',
            'base' => 'main',
        ],
        [],
        [],
        ['REMOTE_ADDR' => WORKSPACE_STORE_CALLER_WG_IP],
    );

    $response->assertCreated();
    $response->assertJsonPath('success.data.workspace.name', 'feature-a');
    $response->assertJsonPath('success.data.workspace.project', 'demo');
    $response->assertJsonPath('success.data.workspace.path', '/home/nckrtl/apps/demo/.worktrees/feature-a');
    $response->assertJsonPath('success.data.workspace.lifecycle_status', 'active');
    $response->assertJsonPath('success.data.result.action', 'created');
    $response->assertJsonPath('success.meta.base', 'main');

    $workspace = Workspace::query()
        ->where('name', 'feature-a')
        ->where('app_id', 1)
        ->first();

    expect($workspace)->not->toBeNull();
});

it('creates a workspace on the selected app instance node', function (): void {
    $shell = new WorkspaceStoreRuntimeContainerShell;
    app()->instance(RemoteShell::class, $shell);

    $canonicalNode = createTestAppHostNode([
        'name' => 'beast',
        'wireguard_address' => '10.6.0.17',
        'tld' => 'test',
    ]);
    $localNode = createTestAppHostNode([
        'name' => 'NMBP',
        'wireguard_address' => '10.6.0.18',
        'tld' => 'nmbp',
    ]);
    $app = Project::factory()->for($canonicalNode, 'node')->create([
        'name' => 'happie',
        'domain' => 'happie.test',
        'path' => '/home/nckrtl/apps/happie',
        'php_version' => '8.5',
        'runtime' => AppRuntimeKind::Php,
    ]);
    $instance = AppInstance::factory()->create([
        'app_id' => $app->id,
        'name' => 'nmbp',
        'driver' => AppInstanceDriver::Orbit,
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $localNode->id,
            path: '/Users/nckrtl/apps/happie',
            domain: 'happie.nmbp',
        ),
    ]);

    $response = $this->call(
        'POST',
        '/api/workspaces',
        [
            'name' => 'recipes',
            'instance' => 'happie.nmbp',
            'base' => 'main',
        ],
        [],
        [],
        ['REMOTE_ADDR' => WORKSPACE_STORE_CALLER_WG_IP],
    );

    $response->assertCreated();
    $response->assertJsonPath('success.data.workspace.name', 'recipes');
    $response->assertJsonPath('success.data.workspace.project', 'happie');
    $response->assertJsonPath('success.data.workspace.instance', 'nmbp');
    $response->assertJsonPath('success.data.workspace.node', 'NMBP');
    $response->assertJsonPath('success.data.workspace.path', '/Users/nckrtl/apps/happie/.worktrees/recipes');
    $response->assertJsonPath('success.data.workspace.url', 'https://recipes.happie.nmbp');

    $workspace = Workspace::query()
        ->where('app_id', $app->id)
        ->where('name', 'recipes')
        ->first();

    $nodeNames = array_values(array_unique(array_map(
        fn (array $call): string => $call['node']->name,
        $shell->calls,
    )));
    $combinedScripts = implode("\n", array_map(fn (array $call): string => $call['script'], $shell->calls));

    expect($workspace)
        ->not
        ->toBeNull()
        ->and($workspace?->app_instance_id)
        ->toBe($instance->id)
        ->and($nodeNames)
        ->toBe(['NMBP'])
        ->and($combinedScripts)
        ->toContain('/Users/nckrtl/apps/happie');
});

it('rejects callers without workspace creation permission', function (): void {
    Node::factory()->create([
        'name' => 'beast',
        'host' => 'beast',
        'wireguard_address' => '10.6.0.8',
        'status' => 'active',
    ]);

    $response = $this->call(
        'POST',
        '/api/workspaces',
        [
            'name' => 'feature-a',
            'instance' => 'demo',
        ],
        [],
        [],
        ['REMOTE_ADDR' => '10.6.0.8'],
    );

    $response->assertStatus(403);
    $response->assertJsonPath('error.code', 'authorization_failed');
    $response->assertJsonPath('error.meta.reason', 'missing_permission');
    $response->assertJsonPath('error.meta.missing_permission', 'workspace:new');
});

it('rejects reserved name main', function (): void {
    $response = $this->call(
        'POST',
        '/api/workspaces',
        [
            'name' => 'main',
            'instance' => 'demo',
        ],
        [],
        [],
        ['REMOTE_ADDR' => WORKSPACE_STORE_CALLER_WG_IP],
    );

    $response->assertStatus(422);
    $response->assertJsonPath('error.code', 'validation_failed');
    $response->assertJsonPath('error.meta.field', 'name');
});

it('rejects invalid workspace names', function (): void {
    $response = $this->call(
        'POST',
        '/api/workspaces',
        [
            'name' => 'Feature_A',
            'instance' => 'demo',
        ],
        [],
        [],
        ['REMOTE_ADDR' => WORKSPACE_STORE_CALLER_WG_IP],
    );

    $response->assertStatus(422);
});

it('rejects duplicate workspace names per app', function (): void {
    Workspace::create([
        'app_id' => 1,
        'app_instance_id' => AppInstance::query()->where('app_id', 1)->valueOrFail('id'),
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::Expected,
    ]);

    $response = $this->call(
        'POST',
        '/api/workspaces',
        [
            'name' => 'feature-a',
            'instance' => 'demo',
        ],
        [],
        [],
        ['REMOTE_ADDR' => WORKSPACE_STORE_CALLER_WG_IP],
    );

    $response->assertStatus(422);
    $response->assertJsonPath('error.code', 'workspace.already_exists');
});

it('rejects workspace creation for production app nodes', function (): void {
    $node = createTestAppHostNode([
        'name' => 'prod-1',
        'host' => 'prod-1',
        'wireguard_address' => '10.6.0.8',
    ], role: 'app-prod');
    $app = Project::factory()
        ->for($node, 'node')
        ->create([
            'name' => 'prod',
            'domain' => 'prod.test',
            'path' => '/home/orbit/apps/prod',
            'php_version' => '8.5',
        ]);
    AppInstance::factory()->for($app)->create([
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $node->id,
            path: $app->path,
            document_root: $app->document_root,
            domain: $app->domain,
        ),
    ]);

    $response = $this->call(
        'POST',
        '/api/workspaces',
        [
            'name' => 'feature-a',
            'instance' => 'prod',
        ],
        [],
        [],
        ['REMOTE_ADDR' => WORKSPACE_STORE_CALLER_WG_IP],
    );

    $response->assertStatus(422);
    $response->assertJsonPath('error.code', 'workspace.unsupported_for_production');
    expect(
        Workspace::query()->where('app_id', Project::query()->where('name', 'prod')->value('id'))->exists(),
    )->toBeFalse();
});

it('creates workspace with supported custom php version', function (): void {
    $response = $this->call(
        'POST',
        '/api/workspaces',
        [
            'name' => 'feature-php',
            'instance' => 'demo',
            'php_version' => '8.4',
        ],
        [],
        [],
        ['REMOTE_ADDR' => WORKSPACE_STORE_CALLER_WG_IP],
    );

    $response->assertCreated();
    $response->assertJsonPath('success.data.workspace.php_version', '8.4');

    $workspace = Workspace::query()
        ->where('name', 'feature-php')
        ->first();

    expect($workspace->php_version)->toBe('8.4');
});

it('rejects unsupported php version', function (): void {
    $response = $this->call(
        'POST',
        '/api/workspaces',
        [
            'name' => 'feature-php',
            'instance' => 'demo',
            'php_version' => '8.2',
        ],
        [],
        [],
        ['REMOTE_ADDR' => WORKSPACE_STORE_CALLER_WG_IP],
    );

    $response->assertStatus(422);
    $response->assertJsonPath('error.code', 'validation_failed');
    $response->assertJsonPath('error.meta.field', 'php_version');
});

it('creates php workspace source without converging runtime containers during create (runtime)', function (): void {
    $shell = new WorkspaceStoreRuntimeContainerShell;
    app()->instance(RemoteShell::class, $shell);

    $response = $this->call(
        'POST',
        '/api/workspaces',
        [
            'name' => 'feature-runtime',
            'instance' => 'demo',
            'base' => 'main',
        ],
        [],
        [],
        ['REMOTE_ADDR' => WORKSPACE_STORE_CALLER_WG_IP],
    );

    $response->assertCreated();

    $scripts = array_map(fn (array $call): string => $call['script'], $shell->calls);
    $combined = implode("\n", $scripts);

    // FrankenPHP runtime container converges; FPM pool is not rendered in
    // the steady-state path after ORBIT-RUNTIME-06C (todo 336).
    expect($combined)
        ->toContain('internal:workspace-source:create')
        ->and($combined)
        ->not->toContain('internal:process-docker-container')->and($combined)
        ->not->toContain('/etc/php/8.5/fpm/pool.d/orbit-demo-feature-runtime.conf');
});

it('skips runtime container convergence for static workspaces during create (runtime)', function (): void {
    Project::query()
        ->where('name', 'demo')
        ->update([
            'runtime' => AppRuntimeKind::Static->value,
        ]);

    $shell = new WorkspaceStoreRuntimeContainerShell;
    app()->instance(RemoteShell::class, $shell);

    $response = $this->call(
        'POST',
        '/api/workspaces',
        [
            'name' => 'feature-static',
            'instance' => 'demo',
            'base' => 'main',
        ],
        [],
        [],
        ['REMOTE_ADDR' => WORKSPACE_STORE_CALLER_WG_IP],
    );

    $response->assertCreated();

    $scripts = array_map(fn (array $call): string => $call['script'], $shell->calls);
    $combined = implode("\n", $scripts);

    expect($combined)
        ->not->toContain("'orbit-ws-demo-feature-static'")->and($combined)
        ->not->toContain('docker run -d');
});

it('rejects unauthenticated requests', function (): void {
    $this
        ->call('POST', '/api/workspaces', [
            'name' => 'feature-a',
            'instance' => 'demo',
        ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'authorization_failed');
});

it('rejects missing instance', function (): void {
    $response = $this->call(
        'POST',
        '/api/workspaces',
        [
            'name' => 'feature-a',
            'instance' => 'nonexistent',
        ],
        [],
        [],
        ['REMOTE_ADDR' => WORKSPACE_STORE_CALLER_WG_IP],
    );

    $response->assertStatus(404);
    $response->assertJsonPath('error.code', 'instance.not_found');
});

final class WorkspaceStoreTestShell implements RemoteShell
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        if (str_contains($script, 'internal:workspace-source:create')) {
            return new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode(['success' => ['data' => [], 'meta' => []]], JSON_THROW_ON_ERROR)."\n",
                stderr: '',
                durationMs: 1,
            );
        }

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

final class WorkspaceStoreRuntimeContainerShell implements RemoteShell
{
    /** @var list<array{node: Node, script: string, options: array<string, mixed>}> */
    public array $calls = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->calls[] = ['node' => $node, 'script' => $script, 'options' => $options];

        if (str_contains($script, 'internal:workspace-source:create')) {
            return new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode(['success' => ['data' => [], 'meta' => []]], JSON_THROW_ON_ERROR)."\n",
                stderr: '',
                durationMs: 1,
            );
        }

        if (str_contains($script, 'internal:process-docker-container')) {
            return new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode([
                    'success' => [
                        'data' => ['outcome' => 'created'],
                        'meta' => [],
                    ],
                ], JSON_THROW_ON_ERROR)
                    ."\n",
                stderr: '',
                durationMs: 1,
            );
        }

        if (str_contains($script, 'docker image inspect')) {
            return new RemoteShellResult(exitCode: 0, stdout: '[{"Id":"sha256:abc"}]', stderr: '', durationMs: 1);
        }

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
