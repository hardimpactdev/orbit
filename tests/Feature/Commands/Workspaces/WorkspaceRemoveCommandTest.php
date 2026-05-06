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
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

it('removes workspace intent and owned artifacts from a gateway caller', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    $node = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
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
        'path' => '/home/orbit/apps/docs/workspaces/feature-api',
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
        ->and($remoteShell->scripts[1])->toContain('php artisan migrate:rollback --force')
        ->and($remoteShell->scripts[2])->toContain('/etc/php/8.5/fpm/pool.d/orbit-docs-feature-api.conf')
        ->and($remoteShell->scripts[3])->toContain("rm -rf '/home/orbit/apps/docs/workspaces/feature-api'")
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

it('requires destructive consent in non-interactive mode', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
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
        'is_local' => true,
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

it('denies app callers before side effects', function (): void {
    Node::factory()->create([
        'name' => 'app-local',
        'role' => 'app',
        'is_local' => true,
    ]);

    $workspace = Workspace::factory()->create([
        'name' => 'feature-api',
    ]);
    $remoteShell = new WorkspaceRemoveSequencedRemoteShell([]);
    app()->instance(RemoteShell::class, $remoteShell);

    $exitCode = Artisan::call('workspace:remove', [
        'name' => 'feature-api',
        '--force' => true,
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and(Workspace::query()->whereKey($workspace->id)->exists())->toBeTrue()
        ->and($remoteShell->scripts)->toBe([])
        ->and($payload['error']['code'])->toBe('caller_role_not_allowed');
});

it('preserves files when keep files is requested', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    $workspace = Workspace::factory()->create([
        'name' => 'feature-api',
    ]);
    $remoteShell = new WorkspaceRemoveSequencedRemoteShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
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
        ->and($remoteShell->scripts)->toHaveCount(3)
        ->and(implode("\n", $remoteShell->scripts))->not->toContain('rm -rf')
        ->and($payload['success']['data']['worktree_removed'])->toBeFalse()
        ->and($payload['success']['meta']['kept_files'])->toBeTrue();
});

it('reports cleanup drift as success warnings after intent removal', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    $workspace = Workspace::factory()->create([
        'name' => 'feature-api',
    ]);
    app()->instance(RemoteShell::class, new WorkspaceRemoveSequencedRemoteShell([
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'process failed', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
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

it('forwards configured control callers through the typed gateway request', function (): void {
    Node::factory()->create([
        'name' => 'control-1',
        'role' => 'control',
        'is_local' => true,
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
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return array_shift($this->results) ?? new RemoteShellResult(
            exitCode: 0,
            stdout: '',
            stderr: '',
            durationMs: 1,
        );
    }
}
