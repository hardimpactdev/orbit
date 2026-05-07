<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Http\Gateway\Requests\Apps\UpdateAppRootRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

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

    $remoteShell = new AppRootSequencedRemoteShell([]);
    app()->instance(RemoteShell::class, $remoteShell);

    MockClient::global([
        UpdateAppRootRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'app' => [
                        'name' => 'docs',
                        'node' => 'app-1',
                        'environment' => 'development',
                        'url' => 'https://docs.test',
                        'path' => '/home/orbit/apps/docs',
                        'root' => 'web',
                        'repository' => null,
                        'php_version' => '8.5',
                        'adopted' => false,
                    ],
                    'result' => [
                        'hostname' => 'docs.test',
                        'changed' => true,
                    ],
                ],
                'meta' => [
                    'node' => 'app-1',
                    'artifacts_reenacted' => true,
                    'warnings' => [],
                ],
            ],
        ], 200),
    ]);

    $exitCode = Artisan::call('app:root', [
        'app' => 'docs',
        'root' => 'web',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['app']['root'])->toBe('web')
        ->and(App::query()->count())->toBe(0)
        ->and($remoteShell->scripts)->toBe([]);
});

it('prompts for missing human input and renders the progress tree', function (): void {
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

    $this->artisan('app:root')
        ->expectsQuestion('App name or hostname', 'docs')
        ->expectsQuestion('Document root', 'web')
        ->expectsOutputToContain('┌  Updating App Root')
        ->expectsOutputToContain('○  Apply and verify root change')
        ->expectsOutputToContain('●  Applied and verified root change')
        ->expectsOutputToContain('●  Applied PHP-FPM configuration')
        ->expectsOutputToContain('●  Applied proxy routes')
        ->expectsOutputToContain('└  App root updated')
        ->expectsOutputToContain("SUCCESS: Document root for app 'docs' updated to 'web'.")
        ->expectsOutputToContain("Artifacts successfully re-enacted on node 'app-1'.")
        ->assertExitCode(0);
});

it('renders converged human output when root intent is unchanged', function (): void {
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

    $this->artisan('app:root docs public')
        ->expectsOutputToContain("SUCCESS: Document root for app 'docs' is already 'public'.")
        ->expectsOutputToContain("Artifacts successfully re-enacted on node 'app-1'.")
        ->assertExitCode(0);
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
