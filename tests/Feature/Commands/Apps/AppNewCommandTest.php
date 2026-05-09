<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Http\Gateway\Requests\Apps\CreateAppRequest;
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

it('creates source on the target app node before writing gateway app intent', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    $targetNode = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'tld' => 'test',
        'ssh_user' => 'orbit',
        'status' => 'active',
    ]);

    $remoteShell = new RecordingRemoteShell;
    app()->instance(RemoteShell::class, $remoteShell);

    $exitCode = Artisan::call('app:new', [
        'name' => 'docs',
        '--node' => 'app-1',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($remoteShell->runs)->toHaveCount(4)
        ->and($remoteShell->runs[0]['node'])->toBe($targetNode->id)
        ->and($remoteShell->runs[0]['script'])->toContain("mkdir -p '/home/orbit/apps/docs'")
        ->and(App::query()->where('name', 'docs')->exists())->toBeTrue()
        ->and($payload['success']['data']['result']['action'])->toBe('created')
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
        ->and($payload['success']['meta']['warnings'])->toBe([]);
});

it('canonicalizes github shorthand repositories before source creation and registry write', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'ssh_user' => 'deploy',
        'status' => 'active',
    ]);

    $remoteShell = new RecordingRemoteShell;
    app()->instance(RemoteShell::class, $remoteShell);

    $exitCode = Artisan::call('app:new', [
        'name' => 'api',
        '--node' => 'app-1',
        '--repo' => 'acme/api',
        '--root' => 'web',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($remoteShell->runs[0]['script'])->toContain("git clone 'git@github.com:acme/api.git' '/home/deploy/apps/api'")
        ->and(App::query()->where('name', 'api')->value('repository'))->toBe('git@github.com:acme/api.git')
        ->and($payload['success']['data']['app']['repository'])->toBe('git@github.com:acme/api.git')
        ->and($payload['success']['data']['app']['root'])->toBe('web');
});

it('does not write gateway app intent when source creation fails', function (): void {
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

    $remoteShell = new RecordingRemoteShell(new RemoteShellResult(
        exitCode: 128,
        stdout: '',
        stderr: "permission denied\n",
        durationMs: 5,
    ));
    app()->instance(RemoteShell::class, $remoteShell);

    $exitCode = Artisan::call('app:new', [
        'name' => 'docs',
        '--node' => 'app-1',
        '--repo' => 'git@github.com:acme/docs.git',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and(App::query()->where('name', 'docs')->exists())->toBeFalse()
        ->and($payload['error']['code'])->toBe('app.source_creation_failed')
        ->and($payload['error']['meta'])->toMatchArray([
            'reason' => 'permission denied',
            'transport' => 'ssh',
        ]);
});

it('keeps gateway app intent and reports a warning when runtime enactment needs later convergence', function (): void {
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

    app()->instance(RemoteShell::class, new SequencedRecordingRemoteShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 127, stdout: '', stderr: "php-fpm missing\n", durationMs: 1),
    ]));

    $exitCode = Artisan::call('app:new', [
        'name' => 'docs',
        '--node' => 'app-1',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and(App::query()->where('name', 'docs')->exists())->toBeTrue()
        ->and($payload['success']['meta']['warnings'])->toHaveCount(1)
        ->and($payload['success']['meta']['warnings'][0])->toMatchArray([
            'code' => 'app.php_version_unavailable',
            'family' => 'app',
            'next_command' => 'doctor --fix --family=app --restore',
        ]);
});

it('renders and reloads an app php-fpm pool after app intent is durable', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'ssh_user' => 'orbit',
        'status' => 'active',
    ]);

    $remoteShell = new SequencedRecordingRemoteShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '/usr/sbin/php-fpm', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);
    app()->instance(RemoteShell::class, $remoteShell);

    $exitCode = Artisan::call('app:new', [
        'name' => 'docs',
        '--node' => 'app-1',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['meta']['warnings'])->toBe([])
        ->and($remoteShell->scripts[1])->toContain('/usr/sbin/php-fpm8.5')
        ->and($remoteShell->scripts[1])->toContain('php-fpm8.5')
        ->and($remoteShell->scripts[2])->toContain('/etc/php/8.5/fpm/pool.d/orbit-docs.conf')
        ->and($remoteShell->scripts[2])->toContain('[orbit-docs]')
        ->and($remoteShell->scripts[2])->toContain('listen = /home/orbit/.config/orbit/php/docs.sock')
        ->and($remoteShell->scripts[2])->toContain('sudo systemctl reload');
});

it('records and enacts an app-owned proxy route after app intent is durable', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    $targetNode = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'tld' => 'test',
        'ssh_user' => 'orbit',
        'status' => 'active',
    ]);

    $remoteShell = new SequencedRecordingRemoteShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '/usr/sbin/php-fpm', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);
    app()->instance(RemoteShell::class, $remoteShell);

    $exitCode = Artisan::call('app:new', [
        'name' => 'docs',
        '--node' => 'app-1',
        '--json' => true,
    ]);

    $route = ProxyRoute::query()->where('domain', 'docs.test')->first();

    expect($exitCode)->toBe(0)
        ->and($route)->not->toBeNull()
        ->and($route?->node_id)->toBe($targetNode->id)
        ->and($route?->owner_type)->toBe('app')
        ->and($route?->kind)->toBe('app')
        ->and($route?->config)->toMatchArray([
            'document_root' => '/home/orbit/apps/docs/public',
            'php_socket' => '/home/orbit/.config/orbit/php/docs.sock',
            'tls' => 'internal',
        ])
        ->and($remoteShell->scripts[3])->toContain('/etc/caddy/sites/docs.test.caddy')
        ->and($remoteShell->scripts[3])->toContain('docs.test {')
        ->and($remoteShell->scripts[3])->toContain('tls internal')
        ->and($remoteShell->scripts[3])->toContain('root * /home/orbit/apps/docs/public')
        ->and($remoteShell->scripts[3])->toContain('php_fastcgi unix//home/orbit/.config/orbit/php/docs.sock')
        ->and($remoteShell->scripts[3])->toContain('sudo systemctl reload caddy');
});

it('uses the production domain as the app-owned proxy route domain', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'tld' => 'test',
        'ssh_user' => 'orbit',
        'status' => 'active',
    ]);

    app()->instance(RemoteShell::class, new RecordingRemoteShell);

    $exitCode = Artisan::call('app:new', [
        'name' => 'docs',
        '--node' => 'app-1',
        '--domain' => 'docs.example.com',
        '--json' => true,
    ]);

    expect($exitCode)->toBe(0)
        ->and(ProxyRoute::query()->where('domain', 'docs.example.com')->exists())->toBeTrue()
        ->and(ProxyRoute::query()->where('domain', 'docs.test')->exists())->toBeFalse();
});

it('keeps app and proxy route intent when proxy backend enactment needs later convergence', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'tld' => 'test',
        'ssh_user' => 'orbit',
        'status' => 'active',
    ]);

    app()->instance(RemoteShell::class, new SequencedRecordingRemoteShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '/usr/sbin/php-fpm', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'caddy reload failed', durationMs: 1),
    ]));

    $exitCode = Artisan::call('app:new', [
        'name' => 'docs',
        '--node' => 'app-1',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and(App::query()->where('name', 'docs')->exists())->toBeTrue()
        ->and(ProxyRoute::query()->where('domain', 'docs.test')->exists())->toBeTrue()
        ->and($payload['success']['meta']['warnings'])->toHaveCount(1)
        ->and($payload['success']['meta']['warnings'][0])->toMatchArray([
            'code' => 'proxy.enactment_failed',
            'family' => 'proxy',
            'next_command' => 'doctor --fix --family=proxy --restore',
        ]);
});

it('fails before source creation when the proxy route domain is already registered', function (): void {
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

    ProxyRoute::query()->create([
        'node_id' => $targetNode->id,
        'domain' => 'docs.test',
        'owner_type' => 'custom',
        'kind' => 'proxy',
        'source_hash' => str_repeat('a', 64),
    ]);

    $remoteShell = new RecordingRemoteShell;
    app()->instance(RemoteShell::class, $remoteShell);

    $exitCode = Artisan::call('app:new', [
        'name' => 'docs',
        '--node' => 'app-1',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($remoteShell->runs)->toBe([])
        ->and(App::query()->where('name', 'docs')->exists())->toBeFalse()
        ->and($payload['error']['code'])->toBe('proxy.domain_conflict')
        ->and($payload['error']['meta'])->toMatchArray([
            'domain' => 'docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
        ]);
});

it('fails before remote work when the app name is already registered', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    $existingNode = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'status' => 'active',
    ]);

    App::factory()->create([
        'name' => 'docs',
        'node_id' => $existingNode->id,
    ]);

    $remoteShell = new RecordingRemoteShell;
    app()->instance(RemoteShell::class, $remoteShell);

    $exitCode = Artisan::call('app:new', [
        'name' => 'docs',
        '--node' => 'app-1',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($remoteShell->runs)->toBe([])
        ->and($payload['error']['code'])->toBe('app.collision')
        ->and($payload['error']['meta'])->toMatchArray([
            'name' => 'docs',
            'node' => 'app-1',
        ]);
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

    $remoteShell = new RecordingRemoteShell;
    app()->instance(RemoteShell::class, $remoteShell);

    MockClient::global([
        CreateAppRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'result' => ['action' => 'created'],
                    'app' => [
                        'name' => 'docs',
                        'node' => 'app-1',
                        'environment' => 'development',
                        'url' => 'https://docs.test',
                        'path' => '/home/orbit/apps/docs',
                        'root' => 'public',
                        'repository' => null,
                        'php_version' => '8.5',
                        'adopted' => false,
                    ],
                ],
                'meta' => ['warnings' => []],
            ],
        ], 200),
    ]);

    $exitCode = Artisan::call('app:new', [
        'name' => 'docs',
        '--node' => 'app-1',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['app']['name'])->toBe('docs')
        ->and(App::query()->count())->toBe(0)
        ->and($remoteShell->runs)->toBe([]);
});

final class RecordingRemoteShell implements RemoteShell
{
    /**
     * @var list<array{node: int|null, script: string, options: array<string, mixed>}>
     */
    public array $runs = [];

    public function __construct(
        private readonly ?RemoteShellResult $result = null,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->runs[] = [
            'node' => $node->id,
            'script' => $script,
            'options' => $options,
        ];

        return $this->result ?? new RemoteShellResult(
            exitCode: 0,
            stdout: '',
            stderr: '',
            durationMs: 1,
        );
    }
}

final class SequencedRecordingRemoteShell implements RemoteShell
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
