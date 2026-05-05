<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\ProcessCrashNotification;
use App\Enums\ProcessRestartPolicy;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\ProxyRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

it('removes app intent and owned artifacts from a gateway caller', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    $node = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'tld' => 'test',
        'status' => 'active',
    ]);

    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'path' => '/home/orbit/apps/docs',
        'document_root' => 'public',
        'adopted' => false,
    ]);

    ProxyRoute::query()->create([
        'node_id' => $node->id,
        'domain' => 'docs.test',
        'app_id' => $app->id,
        'owner_type' => 'app',
        'kind' => 'app',
        'source_hash' => str_repeat('a', 64),
    ]);

    Process::query()->create([
        'app_id' => $app->id,
        'name' => 'queue',
        'command' => 'php artisan queue:work',
        'restart_policy' => ProcessRestartPolicy::OnFailure,
        'crash_notification' => ProcessCrashNotification::None,
        'sort_order' => 1,
    ]);

    $remoteShell = new AppRemoveSequencedRemoteShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);
    app()->instance(RemoteShell::class, $remoteShell);

    $exitCode = Artisan::call('app:remove', [
        'app' => 'docs',
        '--force' => true,
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and(App::query()->where('name', 'docs')->exists())->toBeFalse()
        ->and(ProxyRoute::query()->where('domain', 'docs.test')->exists())->toBeFalse()
        ->and(Process::query()->where('name', 'queue')->exists())->toBeFalse()
        ->and($remoteShell->scripts)->toHaveCount(2)
        ->and($remoteShell->scripts[0])->toContain('/etc/php/8.5/fpm/pool.d/orbit-docs.conf')
        ->and($remoteShell->scripts[1])->toContain('/etc/caddy/sites/docs.test.caddy')
        ->and($remoteShell->scripts[1])->toContain('/etc/supervisor/conf.d/orbit_docs_main_queue.conf')
        ->and($remoteShell->scripts[1])->toContain("rm -rf '/home/orbit/apps/docs'")
        ->and($payload['success']['data']['app'])->toMatchArray([
            'name' => 'docs',
            'node' => 'app-1',
            'environment' => 'development',
            'url' => 'https://docs.test',
            'path' => '/home/orbit/apps/docs',
            'root' => 'public',
            'repository' => null,
            'php_version' => '8.5',
            'adopted' => false,
        ])
        ->and($payload['success']['data']['result']['action'])->toBe('removed')
        ->and($payload['success']['data']['cleanup'])->toMatchArray([
            'proxy_routes_removed' => 1,
            'workspaces_removed' => 0,
            'schedules_removed' => 0,
            'processes_removed' => 1,
            'fpm_config_removed' => true,
            'runtime_config_removed' => true,
        ]);
});

it('requires destructive consent in non-interactive mode', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    $app = App::factory()->create([
        'name' => 'docs',
    ]);

    $remoteShell = new AppRemoveSequencedRemoteShell([]);
    app()->instance(RemoteShell::class, $remoteShell);

    $exitCode = Artisan::call('app:remove', [
        'app' => 'docs',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and(App::query()->whereKey($app->id)->exists())->toBeTrue()
        ->and($remoteShell->scripts)->toBe([])
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['field'])->toBe('force');
});

it('returns app not found for already absent apps', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    app()->instance(RemoteShell::class, new AppRemoveSequencedRemoteShell([]));

    $exitCode = Artisan::call('app:remove', [
        'app' => 'docs',
        '--force' => true,
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('app.not_found')
        ->and($payload['error']['meta'])->toMatchArray([
            'name' => 'docs',
        ]);
});

it('denies app callers before side effects', function (): void {
    Node::factory()->create([
        'name' => 'app-local',
        'role' => 'app',
        'is_local' => true,
    ]);

    $app = App::factory()->create([
        'name' => 'docs',
    ]);

    $remoteShell = new AppRemoveSequencedRemoteShell([]);
    app()->instance(RemoteShell::class, $remoteShell);

    $exitCode = Artisan::call('app:remove', [
        'app' => 'docs',
        '--force' => true,
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and(App::query()->whereKey($app->id)->exists())->toBeTrue()
        ->and($remoteShell->scripts)->toBe([])
        ->and($payload['error']['code'])->toBe('caller_role_not_allowed');
});

it('reports node cleanup drift as success warnings after intent removal', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    App::factory()->create([
        'name' => 'docs',
        'adopted' => true,
    ]);

    app()->instance(RemoteShell::class, new AppRemoveSequencedRemoteShell([
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'fpm failed', durationMs: 1),
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'runtime failed', durationMs: 1),
    ]));

    $exitCode = Artisan::call('app:remove', [
        'app' => 'docs',
        '--force' => true,
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and(App::query()->where('name', 'docs')->exists())->toBeFalse()
        ->and($payload['success']['data']['cleanup'])->toMatchArray([
            'fpm_config_removed' => false,
            'runtime_config_removed' => false,
        ])
        ->and($payload['success']['meta']['warnings'])->toHaveCount(2)
        ->and($payload['success']['meta']['warnings'][0]['code'])->toBe('app.fpm_config_extra')
        ->and($payload['success']['meta']['warnings'][1]['code'])->toBe('app.runtime_config_extra');
});

final class AppRemoveSequencedRemoteShell implements RemoteShell
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
