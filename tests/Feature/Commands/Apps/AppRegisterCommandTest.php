<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use App\Models\ProxyRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

it('adopts an existing app path and enacts runtime artifacts from a gateway caller', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    $targetNode = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'tld' => 'test',
        'status' => 'active',
    ]);

    $remoteShell = new AppRegisterSequencedRemoteShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '/usr/sbin/php-fpm8.5', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);
    app()->instance(RemoteShell::class, $remoteShell);

    $exitCode = Artisan::call('app:register', [
        'name' => 'docs',
        '--node' => 'app-1',
        '--path' => '/home/orbit/apps/docs',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $app = App::query()->where('name', 'docs')->first();
    $route = ProxyRoute::query()->where('domain', 'docs.test')->first();

    expect($exitCode)->toBe(0)
        ->and($remoteShell->scripts[0])->toContain("test -d '/home/orbit/apps/docs'")
        ->and($remoteShell->scripts[2])->toContain('/etc/php/8.5/fpm/pool.d/orbit-docs.conf')
        ->and($app)->not->toBeNull()
        ->and($app?->node_id)->toBe($targetNode->id)
        ->and($app?->path)->toBe('/home/orbit/apps/docs')
        ->and($app?->repository)->toBeNull()
        ->and($app?->adopted)->toBeTrue()
        ->and($route)->not->toBeNull()
        ->and($payload['success']['data']['result']['action'])->toBe('adopted')
        ->and($payload['success']['data']['app'])->toMatchArray([
            'name' => 'docs',
            'node' => 'app-1',
            'environment' => 'development',
            'url' => 'https://docs.test',
            'path' => '/home/orbit/apps/docs',
            'root' => 'public',
            'repository' => null,
            'php_version' => '8.5',
            'adopted' => true,
        ])
        ->and($payload['success']['meta'])->toMatchArray([
            'node' => 'app-1',
            'warnings' => [],
        ]);
});

it('converges an already registered app without changing repository metadata', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    $targetNode = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'tld' => 'test',
        'status' => 'active',
    ]);

    App::factory()->create([
        'name' => 'docs',
        'node_id' => $targetNode->id,
        'path' => '/home/orbit/apps/docs',
        'repository' => 'git@github.com:acme/docs.git',
        'adopted' => true,
    ]);

    app()->instance(RemoteShell::class, new AppRegisterSequencedRemoteShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 127, stdout: '', stderr: 'php-fpm missing', durationMs: 1),
    ]));

    $exitCode = Artisan::call('app:register', [
        'name' => 'docs',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and(App::query()->where('name', 'docs')->value('repository'))->toBe('git@github.com:acme/docs.git')
        ->and($payload['success']['data']['result']['action'])->toBe('converged')
        ->and($payload['success']['data']['app']['adopted'])->toBeTrue()
        ->and($payload['success']['meta']['warnings'])->toHaveCount(1)
        ->and($payload['success']['meta']['warnings'][0]['code'])->toBe('app.php_version_unavailable');
});

it('rejects unmanaged registration without a path before remote work', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'status' => 'active',
    ]);

    $remoteShell = new AppRegisterSequencedRemoteShell([]);
    app()->instance(RemoteShell::class, $remoteShell);

    $exitCode = Artisan::call('app:register', [
        'name' => 'docs',
        '--node' => 'app-1',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($remoteShell->scripts)->toBe([])
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['field'])->toBe('path');
});

it('rejects path collisions before registry writes', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    $targetNode = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'status' => 'active',
    ]);

    App::factory()->create([
        'name' => 'legacy',
        'node_id' => $targetNode->id,
        'path' => '/home/orbit/apps/docs',
    ]);

    app()->instance(RemoteShell::class, new AppRegisterSequencedRemoteShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]));

    $exitCode = Artisan::call('app:register', [
        'name' => 'docs',
        '--node' => 'app-1',
        '--path' => '/home/orbit/apps/docs',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and(App::query()->where('name', 'docs')->exists())->toBeFalse()
        ->and($payload['error']['code'])->toBe('app.path_collision')
        ->and($payload['error']['meta'])->toMatchArray([
            'path' => '/home/orbit/apps/docs',
            'existing_app' => 'legacy',
            'node' => 'app-1',
        ]);
});

it('denies app callers before prompts or side effects', function (): void {
    Node::factory()->create([
        'name' => 'app-local',
        'role' => 'app',
        'is_local' => true,
    ]);

    $remoteShell = new AppRegisterSequencedRemoteShell([]);
    app()->instance(RemoteShell::class, $remoteShell);

    $exitCode = Artisan::call('app:register', [
        'name' => 'docs',
        '--node' => 'app-local',
        '--path' => '/home/orbit/apps/docs',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($remoteShell->scripts)->toBe([])
        ->and($payload['error']['code'])->toBe('caller_role_not_allowed');
});

final class AppRegisterSequencedRemoteShell implements RemoteShell
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
