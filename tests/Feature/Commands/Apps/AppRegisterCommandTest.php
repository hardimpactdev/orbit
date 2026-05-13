<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Http\Gateway\Requests\Apps\RegisterAppRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\ProxyRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

it('adopts an existing app path and enacts runtime artifacts from a gateway caller', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
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

it('reports production domain activation as a retryable proxy warning', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'environment' => 'production',
        'status' => 'active',
    ]);

    app()->instance(RemoteShell::class, new AppRegisterSequencedRemoteShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '/usr/sbin/php-fpm8.5', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]));

    $exitCode = Artisan::call('app:register', [
        'name' => 'docs',
        '--node' => 'app-1',
        '--path' => '/home/docs/app',
        '--domain' => 'docs.example.com',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['app']['environment'])->toBe('production')
        ->and($payload['success']['data']['app']['url'])->toBe('https://docs.example.com')
        ->and($payload['success']['meta']['warnings'])->toHaveCount(1)
        ->and($payload['success']['meta']['warnings'][0])->toMatchArray([
            'code' => 'proxy.domain_inactive',
            'family' => 'proxy',
            'next_command' => 'app:register docs --domain=docs.example.com',
        ]);
});

it('renders production activation warnings in human output', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'environment' => 'production',
        'status' => 'active',
    ]);

    app()->instance(RemoteShell::class, new AppRegisterSequencedRemoteShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '/usr/sbin/php-fpm8.5', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]));

    $this->artisan('app:register docs --node=app-1 --path=/home/docs/app --domain=docs.example.com')
        ->expectsConfirmation('Adopt existing app path?', 'yes')
        ->expectsOutputToContain('Warnings:')
        ->expectsOutputToContain("Production domain 'docs.example.com' is not yet active.")
        ->expectsOutputToContain('Retry with: orbit app:register docs --domain=docs.example.com')
        ->assertExitCode(0);
});

it('rejects unmanaged registration without a path before remote work', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
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

it('forwards app-node CLI callers through the typed gateway request without local side effects', function (): void {
    config(['orbit.is_gateway' => false]);

    Node::factory()->create([
        'name' => 'app-local',
        'role' => 'app',
    ]);

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();

    $remoteShell = new AppRegisterSequencedRemoteShell([]);
    app()->instance(RemoteShell::class, $remoteShell);

    MockClient::global([
        RegisterAppRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'result' => ['action' => 'adopted'],
                    'app' => [
                        'name' => 'docs',
                        'node' => 'app-1',
                        'environment' => 'development',
                        'url' => 'https://docs.test',
                        'path' => '/home/orbit/apps/docs',
                        'root' => 'public',
                        'repository' => null,
                        'php_version' => '8.5',
                        'adopted' => true,
                    ],
                ],
                'meta' => [
                    'node' => 'app-1',
                    'warnings' => [],
                ],
            ],
        ], 200),
    ]);

    $exitCode = Artisan::call('app:register', [
        'name' => 'docs',
        '--node' => 'app-local',
        '--path' => '/home/orbit/apps/docs',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($remoteShell->scripts)->toBe([])
        ->and($payload['success']['data']['app']['name'])->toBe('docs');
});

it('forwards configured control callers through the typed gateway request', function (): void {
    config(['orbit.is_gateway' => false]);

    Node::factory()->create([
        'name' => 'control-1',
        'role' => 'control',
    ]);

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();

    $remoteShell = new AppRegisterSequencedRemoteShell([]);
    app()->instance(RemoteShell::class, $remoteShell);

    MockClient::global([
        RegisterAppRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'result' => ['action' => 'adopted'],
                    'app' => [
                        'name' => 'docs',
                        'node' => 'app-1',
                        'environment' => 'development',
                        'url' => 'https://docs.test',
                        'path' => '/home/orbit/apps/docs',
                        'root' => 'public',
                        'repository' => null,
                        'php_version' => '8.5',
                        'adopted' => true,
                    ],
                ],
                'meta' => [
                    'node' => 'app-1',
                    'warnings' => [],
                ],
            ],
        ], 200),
    ]);

    $exitCode = Artisan::call('app:register', [
        'name' => 'docs',
        '--node' => 'app-1',
        '--path' => '/home/orbit/apps/docs',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['result']['action'])->toBe('adopted')
        ->and($payload['success']['data']['app']['name'])->toBe('docs')
        ->and(App::query()->count())->toBe(0)
        ->and($remoteShell->scripts)->toBe([]);
});

it('renders the documented human progress tree and adopted success line', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'tld' => 'test',
        'status' => 'active',
    ]);

    app()->instance(RemoteShell::class, new AppRegisterSequencedRemoteShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '/usr/sbin/php-fpm8.5', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]));

    $this->artisan('app:register docs --node=app-1 --path=/home/orbit/apps/docs')
        ->expectsConfirmation('Adopt existing app path?', 'yes')
        ->expectsOutputToContain('┌  Registering App')
        ->expectsOutputToContain('○  Resolve app intent')
        ->expectsOutputToContain('●  Resolved app intent')
        ->expectsOutputToContain('●  Registered app record or adopted app path')
        ->expectsOutputToContain('●  Applied and verified app runtime')
        ->expectsOutputToContain('●  Applied and verified app routing')
        ->expectsOutputToContain('●  Verified enactment')
        ->expectsOutputToContain("└  App 'docs' registered")
        ->expectsOutputToContain("App 'docs' successfully adopted from path '/home/orbit/apps/docs' on node 'app-1'.")
        ->expectsOutputToContain('URL: https://docs.test')
        ->assertExitCode(0);
});

it('prompts for missing interactive input before adopting an app path', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'tld' => 'test',
        'status' => 'active',
    ]);

    app()->instance(RemoteShell::class, new AppRegisterSequencedRemoteShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '/usr/sbin/php-fpm8.5', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]));

    $this->artisan('app:register')
        ->expectsQuestion('App name', 'docs')
        ->expectsChoice('Target app node', 'app-1', ['app-1'])
        ->expectsQuestion('App path on node', '/home/orbit/apps/docs')
        ->expectsConfirmation('Adopt existing app path?', 'yes')
        ->expectsOutputToContain("App 'docs' successfully adopted from path '/home/orbit/apps/docs' on node 'app-1'.")
        ->assertExitCode(0);
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
