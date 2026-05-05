<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

it('updates app root intent and re-enacts runtime artifacts from a gateway caller', function (): void {
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

    App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'path' => '/home/orbit/apps/docs',
        'document_root' => 'public',
    ]);

    $remoteShell = new AppRootSequencedRemoteShell([
        new RemoteShellResult(exitCode: 0, stdout: '/usr/sbin/php-fpm8.5', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);
    app()->instance(RemoteShell::class, $remoteShell);

    $exitCode = Artisan::call('app:root', [
        'app' => 'docs',
        'root' => 'web',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and(App::query()->where('name', 'docs')->value('document_root'))->toBe('web')
        ->and($remoteShell->scripts[1])->toContain('/etc/php/8.5/fpm/pool.d/orbit-docs.conf')
        ->and($payload['success']['data']['result'])->toMatchArray([
            'hostname' => 'docs.test',
            'changed' => true,
        ])
        ->and($payload['success']['data']['app']['root'])->toBe('web')
        ->and($payload['success']['meta'])->toMatchArray([
            'node' => 'app-1',
            'artifacts_reenacted' => true,
            'warnings' => [],
        ]);
});

it('reports converged no-op when root intent is unchanged', function (): void {
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

    App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'path' => '/home/orbit/apps/docs',
        'document_root' => 'public',
    ]);

    app()->instance(RemoteShell::class, new AppRootSequencedRemoteShell([
        new RemoteShellResult(exitCode: 0, stdout: '/usr/sbin/php-fpm8.5', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]));

    $exitCode = Artisan::call('app:root', [
        'app' => 'docs',
        'root' => 'public',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['result']['changed'])->toBeFalse()
        ->and($payload['success']['meta']['artifacts_reenacted'])->toBeFalse();
});

it('rejects roots that resolve outside the app path', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    $node = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'status' => 'active',
    ]);

    App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'path' => '/home/orbit/apps/docs',
    ]);

    $remoteShell = new AppRootSequencedRemoteShell([]);
    app()->instance(RemoteShell::class, $remoteShell);

    $exitCode = Artisan::call('app:root', [
        'app' => 'docs',
        'root' => '../outside',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($remoteShell->scripts)->toBe([])
        ->and($payload['error']['code'])->toBe('app.invalid_root')
        ->and($payload['error']['meta'])->toMatchArray([
            'field' => 'root',
            'root' => '../outside',
            'app_path' => '/home/orbit/apps/docs',
        ]);
});

it('denies app callers before side effects', function (): void {
    Node::factory()->create([
        'name' => 'app-local',
        'role' => 'app',
        'is_local' => true,
    ]);

    $remoteShell = new AppRootSequencedRemoteShell([]);
    app()->instance(RemoteShell::class, $remoteShell);

    $exitCode = Artisan::call('app:root', [
        'app' => 'docs',
        'root' => 'public',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($remoteShell->scripts)->toBe([])
        ->and($payload['error']['code'])->toBe('caller_role_not_allowed');
});

final class AppRootSequencedRemoteShell implements RemoteShell
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
