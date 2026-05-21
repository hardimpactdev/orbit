<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\WorkspaceLifecyclePhase;
use App\Http\Gateway\Requests\Workspaces\RemoveWorkspaceRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\Process;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Models\WorkspaceStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Output\BufferedOutput;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['orbit.is_gateway' => true]);
});

afterEach(function (): void {
    MockClient::destroyGlobal();
});

it('removes workspace intent and owned artifacts from a gateway caller', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    $node = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'tld' => 'test',
        'user' => 'orbit',
    ]);
    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'path' => '/home/orbit/apps/docs',
        'php_version' => '8.5',
    ]);
    $workspace = Workspace::factory()->create([
        'app_id' => $app->id,
        'name' => 'feature-api',
        'path' => '/home/orbit/apps/docs/.worktrees/feature-api',
        'php_version' => null,
    ]);

    Process::factory()->create([
        'app_id' => $app->id,
        'name' => 'queue',
        'command' => 'php artisan queue:work',
    ]);
    ProxyRoute::factory()->create([
        'node_id' => $node->id,
        'app_id' => $app->id,
        'workspace_id' => $workspace->id,
        'owner_type' => 'workspace',
        'kind' => 'workspace',
        'domain' => 'feature-api.docs.test',
    ]);
    WorkspaceStep::factory()->create([
        'app_id' => $app->id,
        'phase' => WorkspaceLifecyclePhase::Teardown,
        'sort_order' => 1,
        'command' => 'php artisan migrate:rollback --force',
        'timeout_seconds' => 123,
    ]);

    $remoteShell = new WorkspaceRemoveSequencedRemoteShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);
    app()->instance(RemoteShell::class, $remoteShell);

    $exitCode = Artisan::call('workspace:remove', [
        'name' => 'feature-api',
        '--app' => 'docs',
        '--force' => true,
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and(Workspace::query()->whereKey($workspace->id)->exists())->toBeFalse()
        ->and(ProxyRoute::query()->where('domain', 'feature-api.docs.test')->exists())->toBeFalse()
        ->and($remoteShell->scripts)->toHaveCount(4)
        ->and($remoteShell->scripts[0])->toContain('orbit_docs_feature-api_queue')
        ->and($remoteShell->scripts[1])->toBe('php artisan migrate:rollback --force')
        ->and($remoteShell->options[1]['cwd'])->toBe('/home/orbit/apps/docs/.worktrees/feature-api')
        ->and($remoteShell->options[1]['timeout'])->toBe(123)
        ->and($remoteShell->options[1]['env'])->toMatchArray([
            'ORBIT_APP' => 'docs',
            'ORBIT_APP_PATH' => '/home/orbit/apps/docs',
            'ORBIT_WORKSPACE_NAME' => 'feature-api',
            'ORBIT_WORKSPACE_PATH' => '/home/orbit/apps/docs/.worktrees/feature-api',
            'ORBIT_URL' => 'https://feature-api.docs.test',
            'ORBIT_PHP_VERSION' => '8.5',
            'VITE_APP_URL' => 'https://feature-api.docs.test',
            'VITE_VALET_HOST' => 'feature-api.docs.test',
        ])
        ->and($remoteShell->scripts[2])->toContain('/etc/php/8.5/fpm/pool.d/orbit-docs-feature-api.conf')
        ->and($remoteShell->scripts[3])->toContain("rm -rf '/home/orbit/apps/docs/.worktrees/feature-api'")
        ->and($payload['success']['data'])->toMatchArray([
            'name' => 'feature-api',
            'app' => 'docs',
            'action' => 'removed',
            'proxy_routes_removed' => 1,
            'processes_removed' => 1,
            'fpm_config_removed' => true,
            'worktree_removed' => true,
            'teardown_steps_run' => 1,
        ])
        ->and($payload['success']['meta'])->toMatchArray([
            'kept_files' => false,
        ]);
});

it('renders the documented human progress tree while removing a workspace', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    $node = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'tld' => 'test',
        'user' => 'orbit',
    ]);
    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'path' => '/home/orbit/apps/docs',
        'php_version' => '8.5',
    ]);
    Workspace::factory()->create([
        'app_id' => $app->id,
        'name' => 'feature-api',
        'path' => '/home/orbit/apps/docs/.worktrees/feature-api',
    ]);

    app()->instance(RemoteShell::class, new WorkspaceRemoveSequencedRemoteShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]));

    $this->artisan('workspace:remove feature-api --app=docs --force')
        ->expectsOutputToContain('┌  Removing Workspace')
        ->expectsOutputToContain('○  Apply and verify workspace removal')
        ->expectsOutputToContain('●  Applied and verified workspace removal')
        ->expectsOutputToContain('●  Stopped traffic for workspace hostname')
        ->expectsOutputToContain('●  Stopped inherited processes')
        ->expectsOutputToContain('●  Ran teardown steps')
        ->expectsOutputToContain('●  Cleaned workspace PHP-FPM pool')
        ->expectsOutputToContain('●  Removed worktree')
        ->expectsOutputToContain("└  Workspace 'feature-api' removed.")
        ->expectsOutputToContain("Workspace 'feature-api' removed.")
        ->assertExitCode(0);
});

it('renders decorated progress tree glyphs and colors while removing a workspace', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    $node = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'tld' => 'test',
        'user' => 'orbit',
    ]);
    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'path' => '/home/orbit/apps/docs',
        'php_version' => '8.5',
    ]);
    Workspace::factory()->create([
        'app_id' => $app->id,
        'name' => 'feature-api',
        'path' => '/home/orbit/apps/docs/.worktrees/feature-api',
    ]);

    app()->instance(RemoteShell::class, new WorkspaceRemoveSequencedRemoteShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]));

    $output = new BufferedOutput(decorated: true);
    $exitCode = Artisan::call('workspace:remove', [
        'name' => 'feature-api',
        '--app' => 'docs',
        '--force' => true,
    ], $output);
    $buffer = $output->fetch();
    $plainBuffer = preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $buffer) ?? $buffer;

    expect($exitCode)->toBe(0)
        ->and($plainBuffer)->toContain('┌  Removing Workspace')
        ->and($plainBuffer)->toContain('○  Apply and verify workspace removal')
        ->and($plainBuffer)->toContain('●  Applied and verified workspace removal')
        ->and($plainBuffer)->toContain("└  Workspace 'feature-api' removed.")
        ->and($buffer)->toContain("\e[36m○\e[39m")
        ->and($buffer)->toContain("\e[32m●\e[39m");
});

it('requires destructive consent in non-interactive mode', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    $workspace = Workspace::factory()->create([
        'name' => 'feature-api',
    ]);
    $remoteShell = new WorkspaceRemoveSequencedRemoteShell([]);
    app()->instance(RemoteShell::class, $remoteShell);

    $exitCode = Artisan::call('workspace:remove', [
        'name' => 'feature-api',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and(Workspace::query()->whereKey($workspace->id)->exists())->toBeTrue()
        ->and($remoteShell->scripts)->toBe([])
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['field'])->toBe('force');
});

it('returns not found for already absent workspaces', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    app()->instance(RemoteShell::class, new WorkspaceRemoveSequencedRemoteShell([]));

    $exitCode = Artisan::call('workspace:remove', [
        'name' => 'feature-api',
        '--force' => true,
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('workspace.not_found')
        ->and($payload['error']['meta']['name'])->toBe('feature-api');
});

it('preserves files when keep files is requested', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    $workspace = Workspace::factory()->create([
        'name' => 'feature-api',
    ]);
    $remoteShell = new WorkspaceRemoveSequencedRemoteShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);
    app()->instance(RemoteShell::class, $remoteShell);

    $exitCode = Artisan::call('workspace:remove', [
        'name' => 'feature-api',
        '--force' => true,
        '--keep-files' => true,
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and(Workspace::query()->whereKey($workspace->id)->exists())->toBeFalse()
        ->and($remoteShell->scripts)->toHaveCount(2)
        ->and(implode("\n", $remoteShell->scripts))->not->toContain('rm -rf')
        ->and($payload['success']['data']['worktree_removed'])->toBeFalse()
        ->and($payload['success']['meta']['kept_files'])->toBeTrue();
});

it('reports cleanup drift as success warnings after intent removal', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    $workspace = Workspace::factory()->create([
        'name' => 'feature-api',
    ]);
    app()->instance(RemoteShell::class, new WorkspaceRemoveSequencedRemoteShell([
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'process failed', durationMs: 1),
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'fpm failed', durationMs: 1),
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'files failed', durationMs: 1),
    ]));

    $exitCode = Artisan::call('workspace:remove', [
        'name' => 'feature-api',
        '--force' => true,
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and(Workspace::query()->whereKey($workspace->id)->exists())->toBeFalse()
        ->and($payload['success']['data']['processes_removed'])->toBe(0)
        ->and($payload['success']['data']['fpm_config_removed'])->toBeFalse()
        ->and($payload['success']['data']['worktree_removed'])->toBeFalse()
        ->and($payload['success']['meta']['warnings'])->toHaveCount(3)
        ->and($payload['success']['meta']['warnings'][0]['code'])->toBe('process.runtime_unit_extra')
        ->and($payload['success']['meta']['warnings'][1]['code'])->toBe('workspace.artifact_extra')
        ->and($payload['success']['meta']['warnings'][2]['code'])->toBe('workspace.artifact_extra');
});

it('continues workspace teardown after a failed step and reports the failed step warning', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    $node = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
    ]);
    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'path' => '/home/orbit/apps/docs',
    ]);
    $workspace = Workspace::factory()->create([
        'app_id' => $app->id,
        'name' => 'feature-api',
        'path' => '/home/orbit/apps/docs/.worktrees/feature-api',
    ]);
    $failedStep = WorkspaceStep::factory()->create([
        'app_id' => $app->id,
        'phase' => WorkspaceLifecyclePhase::Teardown,
        'sort_order' => 1,
        'command' => 'php artisan cleanup:first',
        'timeout_seconds' => 11,
    ]);
    WorkspaceStep::factory()->create([
        'app_id' => $app->id,
        'phase' => WorkspaceLifecyclePhase::Teardown,
        'sort_order' => 2,
        'command' => 'php artisan cleanup:second',
        'timeout_seconds' => 22,
    ]);

    $remoteShell = new WorkspaceRemoveSequencedRemoteShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 17, stdout: '', stderr: 'first failed', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);
    app()->instance(RemoteShell::class, $remoteShell);

    $exitCode = Artisan::call('workspace:remove', [
        'name' => 'feature-api',
        '--app' => 'docs',
        '--force' => true,
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and(Workspace::query()->whereKey($workspace->id)->exists())->toBeFalse()
        ->and($remoteShell->scripts[1])->toBe('php artisan cleanup:first')
        ->and($remoteShell->scripts[2])->toBe('php artisan cleanup:second')
        ->and($remoteShell->options[1]['timeout'])->toBe(11)
        ->and($remoteShell->options[2]['timeout'])->toBe(22)
        ->and($payload['success']['data']['teardown_steps_run'])->toBe(2)
        ->and($payload['success']['meta']['warnings'])->toHaveCount(1)
        ->and($payload['success']['meta']['warnings'][0]['code'])->toBe('workspace.teardown_step_failed')
        ->and($payload['success']['meta']['warnings'][0]['step_id'])->toBe((string) $failedStep->id)
        ->and($payload['success']['meta']['warnings'][0]['exit_code'])->toBe('17');
});

it('uses the app context resolved from the current workspace directory', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    $node = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
    ]);
    $docs = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
    ]);
    $admin = App::factory()->create([
        'name' => 'admin',
        'node_id' => $node->id,
    ]);
    $workspacePath = sys_get_temp_dir().'/orbit-workspace-remove-cwd-'.bin2hex(random_bytes(4));
    $childPath = "{$workspacePath}/nested";
    mkdir($childPath, 0777, true);

    $workspace = Workspace::factory()->create([
        'app_id' => $docs->id,
        'name' => 'feature-api',
        'path' => $workspacePath,
    ]);
    Workspace::factory()->create([
        'app_id' => $admin->id,
        'name' => 'feature-api',
    ]);

    $remoteShell = new WorkspaceRemoveSequencedRemoteShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);
    app()->instance(RemoteShell::class, $remoteShell);

    $originalCwd = getcwd();
    chdir($childPath);

    try {
        $exitCode = Artisan::call('workspace:remove', [
            '--force' => true,
            '--json' => true,
        ]);
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        File::deleteDirectory($workspacePath);
    }

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['name'])->toBe('feature-api')
        ->and($payload['success']['data']['app'])->toBe('docs')
        ->and(Workspace::query()->whereKey($workspace->id)->exists())->toBeFalse()
        ->and(Workspace::query()->where('app_id', $admin->id)->where('name', 'feature-api')->exists())->toBeTrue();
});

it('forwards configured non-gateway callers through the typed gateway request', function (): void {
    config(['orbit.is_gateway' => false]);

    Node::factory()->create([
        'name' => 'control-1',
        'role' => 'control',
    ]);

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();

    $workspace = Workspace::factory()->create([
        'name' => 'feature-api',
    ]);
    $remoteShell = new WorkspaceRemoveSequencedRemoteShell([]);
    app()->instance(RemoteShell::class, $remoteShell);

    MockClient::global([
        RemoveWorkspaceRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'name' => 'feature-api',
                    'app' => 'docs',
                    'action' => 'removed',
                    'proxy_routes_removed' => 1,
                    'processes_removed' => 1,
                    'fpm_config_removed' => true,
                    'worktree_removed' => false,
                    'teardown_steps_run' => 0,
                ],
                'meta' => [
                    'kept_files' => true,
                ],
            ],
        ]),
    ]);

    $exitCode = Artisan::call('workspace:remove', [
        'name' => 'feature-api',
        '--app' => 'docs',
        '--keep-files' => true,
        '--force' => true,
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and(Workspace::query()->whereKey($workspace->id)->exists())->toBeTrue()
        ->and($remoteShell->scripts)->toBe([])
        ->and($payload['success']['data'])->toMatchArray([
            'name' => 'feature-api',
            'app' => 'docs',
            'action' => 'removed',
            'worktree_removed' => false,
        ])
        ->and($payload['success']['meta']['kept_files'])->toBeTrue();
});

final class WorkspaceRemoveSequencedRemoteShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $options = [];

    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;
        $this->options[] = $options;

        return array_shift($this->results) ?? new RemoteShellResult(
            exitCode: 0,
            stdout: '',
            stderr: '',
            durationMs: 1,
        );
    }
}
