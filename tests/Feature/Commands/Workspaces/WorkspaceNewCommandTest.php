<?php

declare(strict_types=1);

use App\Contracts\OpenCodeClientFactory;
use App\Contracts\RemoteShell;
use App\Contracts\WorkspaceSourceDriver;
use App\Contracts\WorkspaceSourceDrivers;
use App\Data\RemoteShell\RemoteShellResult;
use App\Data\Workspaces\WorkspaceProvisionResult;
use App\Enums\WorkspaceLifecycleStatus;
use App\Http\Gateway\WorkspaceNewGatewayStreamClient;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use HardImpact\OpenCode\OpenCode;
use HardImpact\OpenCode\Requests\Projects\GetCurrentProject;
use HardImpact\OpenCode\Requests\Sessions\CreateSession;
use HardImpact\OpenCode\Requests\Worktrees\CreateWorktree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['orbit.is_gateway' => true]);
});

beforeEach(function (): void {
    DB::table('nodes')->insert([
        [
            'name' => 'gateway',
            'role' => 'gateway',
            'host' => 'gateway',
            'orbit_path' => '/home/gateway/orbit',
            'status' => 'active',
            'tld' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'name' => 'app-1',
            'role' => 'app',
            'host' => 'app-1',
            'orbit_path' => '/home/nckrtl/orbit',
            'status' => 'active',
            'tld' => 'beast',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    DB::table('node_roles')->insert([
        [
            'node_id' => DB::table('nodes')->where('name', 'gateway')->value('id'),
            'role' => 'gateway',
            'status' => 'active',
            'settings' => json_encode([], JSON_THROW_ON_ERROR),
            'last_error' => null,
            'converged_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'node_id' => DB::table('nodes')->where('name', 'app-1')->value('id'),
            'role' => 'app-development',
            'status' => 'active',
            'settings' => json_encode(['tld' => 'beast'], JSON_THROW_ON_ERROR),
            'last_error' => null,
            'converged_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    DB::table('apps')->insert([
        [
            'name' => 'demo',
            'domain' => 'demo.beast',
            'node_id' => 2,
            'path' => '/home/nckrtl/apps/demo',
            'php_version' => '8.5',
            'environment' => 'development',
            'document_root' => 'public',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    app()->instance(RemoteShell::class, new WorkspaceNewTestShell);
});

it('creates a workspace for a gateway caller', function (): void {
    $exitCode = Artisan::call('workspace:new', [
        'name' => 'feature-a',
        '--app' => 'demo',
        '--json' => true,
    ]);

    $output = Artisan::output();
    $payload = json_decode($output, true);

    expect($exitCode)->toBe(0);
    expect($payload['success']['data']['workspace']['name'])->toBe('feature-a');
    expect($payload['success']['data']['workspace']['app'])->toBe('demo');
    expect($payload['success']['data']['result'])->toBe(['action' => 'created']);
    expect($payload['success']['data']['workspace']['path'])->toBe('/home/nckrtl/apps/demo/.worktrees/feature-a');
    expect($payload['success']['data']['workspace']['url'])->toBe('https://feature-a.demo.beast');
    expect($payload['success']['data']['workspace']['lifecycle_status'])->toBe('active');
    expect($payload['success']['meta']['base'])->toBe('main');

    $workspace = Workspace::query()
        ->where('name', 'feature-a')
        ->where('app_id', 1)
        ->first();

    expect($workspace)->not->toBeNull();
    expect($workspace->path)->toBe('/home/nckrtl/apps/demo/.worktrees/feature-a');
});

it('runs remote worktree provisioning before setup', function (): void {
    $shell = new WorkspaceNewTestShell;
    app()->instance(RemoteShell::class, $shell);

    $exitCode = Artisan::call('workspace:new', [
        'name' => 'feature-a',
        '--app' => 'demo',
        '--base' => 'feature/source',
        '--json' => true,
    ]);

    expect($exitCode)->toBe(0);
    expect(implode("\n---\n", $shell->scripts))
        ->toContain('git -C "$app_path" worktree add "$relative_path" -b "$workspace_name" "$base_ref"')
        ->toContain("workspace_name='feature-a'")
        ->toContain("base_ref='feature/source'");
});

it('creates a Polyscope workspace when the source driver resolves Polyscope', function (): void {
    $node = Node::query()->where('name', 'app-1')->firstOrFail();
    $node->forceFill(['agent_ide_config' => ['adapter' => 'polyscope']])->save();

    $shell = new WorkspaceNewTestShell;
    $polyscope = new WorkspaceNewPolyscopeDriverFake(
        path: '/home/nckrtl/.polyscope/clones/demo/feature-poly',
        workspaceId: 'poly-workspace-123',
    );

    app()->instance(RemoteShell::class, $shell);
    app()->instance(WorkspaceSourceDrivers::class, new WorkspaceNewSourceDriversFake(
        driver: $polyscope,
        effectiveAdapter: 'polyscope',
    ));

    $exitCode = Artisan::call('workspace:new', [
        'name' => 'feature-poly',
        '--app' => 'demo',
        '--base' => 'feature/source',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0);
    expect($payload['success']['data']['workspace']['path'])->toBe('/home/nckrtl/.polyscope/clones/demo/feature-poly');
    expect($payload['success']['data']['workspace']['agent_ide'])->toBe([
        'adapter' => 'polyscope',
        'workspace_id' => 'poly-workspace-123',
    ]);
    expect($polyscope->requests)->toBe([
        [
            'app' => 'demo',
            'node' => 'app-1',
            'name' => 'feature-poly',
            'base' => 'feature/source',
        ],
    ]);
    expect(implode("\n---\n", $shell->scripts))->not->toContain('git -C "$app_path" worktree add');

    $workspace = Workspace::query()
        ->where('name', 'feature-poly')
        ->firstOrFail();

    expect($workspace->path)->toBe('/home/nckrtl/.polyscope/clones/demo/feature-poly');
    expect($workspace->agent_ide)->toBe('polyscope');
    expect($workspace->agent_ide_workspace_id)->toBe('poly-workspace-123');
});

it('creates an OpenCode workspace when the source driver resolves OpenCode', function (): void {
    $node = Node::query()->where('name', 'app-1')->firstOrFail();
    $node->forceFill(['agent_ide_config' => ['adapter' => 'opencode']])->save();

    $mock = new MockClient([
        MockResponse::make(workspaceNewOpenCodeProjectPayload(sandboxes: [])),
        MockResponse::make([]),
        MockResponse::make(workspaceNewOpenCodeWorkspacePayload()),
        MockResponse::make(workspaceNewOpenCodeSessionPayload()),
    ]);
    $client = new OpenCode('http://opencode.test');
    $client->withMockClient($mock);

    app()->instance(OpenCodeClientFactory::class, new WorkspaceNewOpenCodeClientFactory($client));

    $shell = new WorkspaceNewTestShell;
    app()->instance(RemoteShell::class, $shell);

    $exitCode = Artisan::call('workspace:new', [
        'name' => 'feature-open',
        '--app' => 'demo',
        '--base' => 'feature/source',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0);
    expect($payload['success']['data']['workspace']['path'])->toBe('/home/nckrtl/apps/demo/.worktrees/feature-open');
    expect($payload['success']['data']['workspace']['agent_ide'])->toBe([
        'adapter' => 'opencode',
        'workspace_id' => 'sess_feature_open',
    ]);
    expect(implode("\n---\n", $shell->scripts))
        ->toContain('git -C "$workspace_path" branch -m "$workspace_name"')
        ->toContain('git -C "$workspace_path" reset --hard "$base_ref"');

    $mock->assertSentCount(1, GetCurrentProject::class);
    $mock->assertSentCount(1, CreateWorktree::class);
    $mock->assertSentCount(1, CreateSession::class);

    $workspace = Workspace::query()
        ->where('name', 'feature-open')
        ->firstOrFail();

    expect($workspace->agent_ide)->toBe('opencode');
    expect($workspace->agent_ide_workspace_id)->toBe('sess_feature_open');
});

it('fails before writing intent when source provisioning fails', function (): void {
    app()->instance(RemoteShell::class, new WorkspaceNewSequencedTestShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 2, stdout: '', stderr: 'worktree failed', durationMs: 1),
    ]));

    $exitCode = Artisan::call('workspace:new', [
        'name' => 'feature-warning',
        '--app' => 'demo',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(1);
    expect($payload['error']['code'])->toBe('workspace.source_create_failed');
    expect($payload['error']['meta'])->toMatchArray([
        'driver' => 'worktree',
        'node' => 'app-1',
        'app' => 'demo',
        'workspace' => 'feature-warning',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-warning',
    ]);
    expect(Workspace::query()->where('name', 'feature-warning')->exists())->toBeFalse();
});

it('rejects reserved name main', function (): void {
    $exitCode = Artisan::call('workspace:new', [
        'name' => 'main',
        '--app' => 'demo',
        '--json' => true,
    ]);

    $output = Artisan::output();
    $payload = json_decode($output, true);

    expect($exitCode)->toBe(1);
    expect($payload['error']['code'])->toBe('validation_failed');
    expect($payload['error']['meta']['field'])->toBe('name');
});

it('rejects invalid workspace names', function (): void {
    $exitCode = Artisan::call('workspace:new', [
        'name' => 'Feature_A',
        '--app' => 'demo',
        '--json' => true,
    ]);

    $output = Artisan::output();
    $payload = json_decode($output, true);

    expect($exitCode)->toBe(1);
    expect($payload['error']['code'])->toBe('validation_failed');
});

it('rejects duplicate workspace names per app', function (): void {
    Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::Expected,
    ]);

    $exitCode = Artisan::call('workspace:new', [
        'name' => 'feature-a',
        '--app' => 'demo',
        '--json' => true,
    ]);

    $output = Artisan::output();
    $payload = json_decode($output, true);

    expect($exitCode)->toBe(1);
    expect($payload['error']['code'])->toBe('workspace.already_exists');
});

it('creates workspace with supported custom php version', function (): void {
    $exitCode = Artisan::call('workspace:new', [
        'name' => 'feature-php',
        '--app' => 'demo',
        '--php-version' => '8.5',
        '--json' => true,
    ]);

    $output = Artisan::output();
    $payload = json_decode($output, true);

    expect($exitCode)->toBe(0);
    expect($payload['success']['data']['workspace']['php_version'])->toBe('8.5');

    $workspace = Workspace::query()
        ->where('name', 'feature-php')
        ->first();

    expect($workspace->php_version)->toBe('8.5');
});

it('rejects unsupported php versions', function (): void {
    $exitCode = Artisan::call('workspace:new', [
        'name' => 'feature-php',
        '--app' => 'demo',
        '--php-version' => '8.4',
        '--json' => true,
    ]);

    $output = Artisan::output();
    $payload = json_decode($output, true);

    expect($exitCode)->toBe(1);
    expect($payload['error']['code'])->toBe('validation_failed');
    expect($payload['error']['meta']['field'])->toBe('php_version');
});

it('renders human output without json', function (): void {
    $this->artisan('workspace:new', [
        'name' => 'feature-human',
        '--app' => 'demo',
    ])
        ->expectsOutputToContain("Workspace 'feature-human' created on app 'demo' (node 'app-1').")
        ->expectsOutputToContain('URL: https://feature-human.demo.beast')
        ->assertSuccessful();
});

it('renders the documented progress tree and final tree state for human output', function (): void {
    $this->artisan('workspace:new', [
        'name' => 'feature-tree',
        '--app' => 'demo',
    ])
        ->expectsOutputToContain('┌  Creating Workspace')
        ->expectsOutputToContain('○  Provision worktree on app-1')
        ->expectsOutputToContain('●  Provisioned worktree on app-1')
        ->expectsOutputToContain('●  Registered proxy routes')
        ->expectsOutputToContain('●  Installed PHP-FPM artifacts')
        ->expectsOutputToContain('●  Rendered inherited runtime units')
        ->expectsOutputToContain("└  Workspace 'feature-tree' created")
        ->expectsOutputToContain("Workspace 'feature-tree' created on app 'demo' (node 'app-1').")
        ->expectsOutputToContain('URL: https://feature-tree.demo.beast')
        ->assertSuccessful();
});

it('streams progress for forwarded human create calls', function (): void {
    config(['orbit.is_gateway' => false]);

    app()->instance(WorkspaceNewGatewayStreamClient::class, new WorkspaceNewTestStreamClient);

    $this->artisan('workspace:new', [
        'name' => 'feature-stream',
        '--app' => 'demo',
    ])
        ->expectsOutputToContain('┌  Creating Workspace')
        ->expectsOutputToContain('●  Provisioned Polyscope workspace on app-1')
        ->expectsOutputToContain("└  Workspace 'feature-stream' created")
        ->expectsOutputToContain("Workspace 'feature-stream' created on app 'demo' (node 'app-1').")
        ->expectsOutputToContain('URL: https://feature-stream.demo.beast')
        ->doesntExpectOutputToContain("\e[?25h")
        ->assertSuccessful();
});

it('requires name in non-interactive mode', function (): void {
    $exitCode = Artisan::call('workspace:new', [
        '--app' => 'demo',
        '--json' => true,
    ]);

    $output = Artisan::output();
    $payload = json_decode($output, true);

    expect($exitCode)->toBe(1);
    expect($payload['error']['code'])->toBe('validation_failed');
    expect($payload['error']['meta']['field'])->toBe('name');
});

final class WorkspaceNewTestShell implements RemoteShell
{
    /** @var list<string> */
    public array $scripts = [];

    /** @var list<array<string, mixed>> */
    public array $options = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;
        $this->options[] = $options;

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

final class WorkspaceNewSequencedTestShell implements RemoteShell
{
    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return array_shift($this->results) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

final class WorkspaceNewTestStreamClient extends WorkspaceNewGatewayStreamClient
{
    public function run(string $name, string $app, string $base, ?string $phpVersion, callable $onEvent): int
    {
        $onEvent('tree', [
            'title' => 'Creating Workspace',
            'steps' => [
                [
                    'key' => 'provision_workspace_source',
                    'label' => 'Provision Polyscope workspace on app-1',
                    'doneLabel' => 'Provisioned Polyscope workspace on app-1',
                ],
            ],
        ]);
        $onEvent('step', [
            'key' => 'provision_workspace_source',
            'status' => 'start',
        ]);
        $onEvent('step', [
            'key' => 'provision_workspace_source',
            'status' => 'done',
            'message' => '/home/nckrtl/.polyscope/clones/demo/feature-stream',
        ]);
        $onEvent('complete', [
            'exit_code' => 0,
            'data' => [
                'footer' => "Workspace 'feature-stream' created",
                'result' => [
                    'result' => ['action' => 'created'],
                    'workspace' => [
                        'name' => 'feature-stream',
                        'app' => 'demo',
                        'node' => 'app-1',
                        'path' => '/home/nckrtl/.polyscope/clones/demo/feature-stream',
                        'url' => 'https://feature-stream.demo.beast',
                        'php_version' => '8.5',
                        'php_inherited' => true,
                        'agent_ide' => [
                            'adapter' => 'polyscope',
                            'workspace_id' => 'poly-workspace-123',
                        ],
                        'adopted' => false,
                        'lifecycle_status' => 'active',
                    ],
                    'meta' => [
                        'node' => 'app-1',
                        'base' => 'main',
                        'http_probe' => ['reachable' => true, 'status' => '200'],
                        'warnings' => [],
                    ],
                ],
            ],
        ]);

        return 0;
    }
}

final class WorkspaceNewSourceDriversFake implements WorkspaceSourceDrivers
{
    public function __construct(
        private readonly WorkspaceSourceDriver $driver,
        private readonly ?string $effectiveAdapter,
    ) {}

    public function resolve(App $app): WorkspaceSourceDriver
    {
        return $this->driver;
    }

    public function effectiveAdapter(App $app): ?string
    {
        return $this->effectiveAdapter;
    }

    public function progressLabels(App $app, Node $node): array
    {
        if ($this->effectiveAdapter === 'polyscope') {
            return [
                'label' => "Provision Polyscope workspace on {$node->name}",
                'done_label' => "Provisioned Polyscope workspace on {$node->name}",
            ];
        }

        return [
            'label' => "Provision worktree on {$node->name}",
            'done_label' => "Provisioned worktree on {$node->name}",
        ];
    }
}

final class WorkspaceNewPolyscopeDriverFake implements WorkspaceSourceDriver
{
    /** @var list<array{app: string, node: string, name: string, base: string}> */
    public array $requests = [];

    public function __construct(
        private readonly string $path,
        private readonly string $workspaceId,
    ) {}

    public function create(App $app, Node $node, string $name, string $base): WorkspaceProvisionResult
    {
        $this->requests[] = [
            'app' => $app->name,
            'node' => $node->name,
            'name' => $name,
            'base' => $base,
        ];

        return new WorkspaceProvisionResult(
            name: $name,
            path: $this->path,
            agentIde: 'polyscope',
            agentIdeWorkspaceId: $this->workspaceId,
        );
    }
}

/**
 * @param  list<string>  $sandboxes
 * @return array<string, mixed>
 */
function workspaceNewOpenCodeProjectPayload(array $sandboxes): array
{
    return [
        'id' => 'proj_demo',
        'worktree' => '/home/nckrtl/apps/demo',
        'vcs' => 'git',
        'time' => ['created' => 1, 'updated' => 1],
        'sandboxes' => $sandboxes,
    ];
}

/**
 * @return array<string, mixed>
 */
function workspaceNewOpenCodeWorkspacePayload(): array
{
    return [
        'name' => 'feature-open',
        'branch' => 'opencode/feature-open',
        'directory' => '/home/nckrtl/apps/demo/.worktrees/feature-open',
    ];
}

/**
 * @return array<string, mixed>
 */
function workspaceNewOpenCodeSessionPayload(): array
{
    return [
        'id' => 'sess_feature_open',
        'title' => 'feature-open',
        'directory' => '/home/nckrtl/apps/demo/.worktrees/feature-open',
    ];
}

final readonly class WorkspaceNewOpenCodeClientFactory implements OpenCodeClientFactory
{
    public function __construct(
        private OpenCode $client,
    ) {}

    public function forApp(App $app): OpenCode
    {
        return $this->client;
    }
}
