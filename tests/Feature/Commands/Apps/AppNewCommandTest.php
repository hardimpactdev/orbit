<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\RemoteShell\RemoteShellResult;
use App\Http\Gateway\Requests\Apps\CreateAppRequest;
use App\Http\Gateway\Requests\Nodes\ListNodesRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\LocalNodeDefault;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\ProxyRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\Fakes\SiteCertificateInstallerFake;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);
});

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function assignAppNewRole(Node $node, string $role, string $status = 'active', array $settings = []): NodeRoleAssignment
{
    return NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => $status,
        'settings' => $settings,
    ]);
}

it('creates source on the target app node before writing gateway app intent', function (): void {
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
    assignAppNewRole($targetNode, 'app-development', settings: ['tld' => 'test']);

    $remoteShell = new RecordingRemoteShell;
    app()->instance(RemoteShell::class, $remoteShell);

    $exitCode = Artisan::call('app:new', [
        'name' => 'docs',
        '--node' => 'app-1',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($remoteShell->runs)->toHaveCount(6)
        ->and($remoteShell->runs[0]['node'])->toBe($targetNode->id)
        ->and($remoteShell->runs[0]['script'])->toContain("sudo install -d -m 755 -o 'orbit' -g 'orbit' '/home/orbit/apps' '/home/orbit/apps/docs'")
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

it('uses local node default in non-interactive mode when node option is missing', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    $targetNode = Node::factory()->create([
        'name' => 'app-2',
        'role' => 'app',
        'environment' => 'development',
        'tld' => 'test',
        'status' => 'active',
    ]);
    assignAppNewRole($targetNode, 'app-development', settings: ['tld' => 'test']);

    LocalNodeDefault::query()->create([
        'default_node_name' => 'app-2',
    ]);

    $remoteShell = new RecordingRemoteShell;
    app()->instance(RemoteShell::class, $remoteShell);

    $exitCode = Artisan::call('app:new', [
        'name' => 'docs',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($remoteShell->runs[0]['node'])->toBe($targetNode->id)
        ->and($payload['success']['data']['app']['node'])->toBe('app-2')
        ->and(App::query()->where('name', 'docs')->value('node_id'))->toBe($targetNode->id);
});

it('uses a configured gateway-local default node when it has active app-development despite legacy shadow mismatch', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    $targetNode = Node::factory()->create([
        'name' => 'app-2',
        'role' => 'database',
        'environment' => 'production',
        'tld' => 'test',
        'status' => 'active',
    ]);
    assignAppNewRole($targetNode, 'app-development', settings: ['tld' => 'test']);

    LocalNodeDefault::query()->create([
        'default_node_name' => 'app-2',
    ]);

    $remoteShell = new RecordingRemoteShell;
    app()->instance(RemoteShell::class, $remoteShell);

    $exitCode = Artisan::call('app:new', [
        'name' => 'docs',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($remoteShell->runs[0]['node'])->toBe($targetNode->id)
        ->and($payload['success']['data']['app']['node'])->toBe('app-2');
});

it('ignores a configured gateway-local default node when it only has legacy app-development shadows', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    Node::factory()->create([
        'name' => 'app-2',
        'role' => 'app',
        'environment' => 'development',
        'tld' => 'test',
        'status' => 'active',
    ]);

    LocalNodeDefault::query()->create([
        'default_node_name' => 'app-2',
    ]);

    $remoteShell = new RecordingRemoteShell;
    app()->instance(RemoteShell::class, $remoteShell);

    $exitCode = Artisan::call('app:new', [
        'name' => 'docs',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($remoteShell->runs)->toBe([])
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['field'])->toBe('node');
});

it('uses a visible control-mode default node based on active app-development payload roles, not legacy shadows', function (): void {
    config(['orbit.is_gateway' => false]);

    Node::factory()->create([
        'name' => 'control-1',
        'role' => 'control',
    ]);

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();

    LocalNodeDefault::query()->create([
        'default_node_name' => 'app-1',
    ]);

    $remoteShell = new RecordingRemoteShell;
    app()->instance(RemoteShell::class, $remoteShell);

    $mock = MockClient::global([
        ListNodesRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'nodes' => [[
                        'name' => 'app-1',
                        'role' => 'database',
                        'environment' => 'production',
                        'status' => 'active',
                        'roles' => [
                            ['role' => 'app-development', 'status' => 'active'],
                        ],
                    ]],
                ],
            ],
        ], 200),
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
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['app']['node'])->toBe('app-1');

    $mock->assertSent(fn (mixed $request): bool => $request instanceof ListNodesRequest
        && $request->role === null
        && $request->environment === null);
    $mock->assertSent(fn (mixed $request): bool => $request instanceof CreateAppRequest
        && $request->node === 'app-1');
});

it('accepts active app-production nodes for production app creation on the gateway', function (): void {
    $router = Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'wireguard_address' => '10.6.0.2',
    ]);
    assignAppNewRole($router, 'router');

    $targetNode = Node::factory()->create([
        'name' => 'prod-1',
        'role' => 'app',
        'status' => 'active',
        'tld' => null,
        'wireguard_address' => '10.6.0.5',
    ]);
    assignAppNewRole($targetNode, 'ingress');
    assignAppNewRole($targetNode, 'app-production', settings: ['ingress_node_id' => $targetNode->id]);

    $remoteShell = new RecordingRemoteShell;
    app()->instance(RemoteShell::class, $remoteShell);

    $exitCode = Artisan::call('app:new', [
        'name' => 'docs',
        '--node' => 'prod-1',
        '--domain' => 'docs.example.com',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and(collect($remoteShell->runs)->pluck('node')->all())->toContain($router->id, $targetNode->id)
        ->and($payload['success']['data']['app']['environment'])->toBe('production')
        ->and($payload['success']['data']['app']['url'])->toBe('https://docs.example.com');
});

it('rejects gateway-local app creation on database-only nodes', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    $targetNode = Node::factory()->create([
        'name' => 'db-1',
        'role' => 'app',
        'status' => 'active',
    ]);
    assignAppNewRole($targetNode, 'database');

    $remoteShell = new RecordingRemoteShell;
    app()->instance(RemoteShell::class, $remoteShell);

    $exitCode = Artisan::call('app:new', [
        'name' => 'docs',
        '--node' => 'db-1',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($remoteShell->runs)->toBe([])
        ->and($payload['error']['code'])->toBe('app.ineligible_node')
        ->and($payload['error']['meta']['required_role'])->toBe('app-development');
});

it('rejects gateway-local app creation on pending app-development nodes', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    $targetNode = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'status' => 'active',
    ]);
    assignAppNewRole($targetNode, 'app-development', 'pending', ['tld' => 'test']);

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
        ->and($payload['error']['code'])->toBe('app.ineligible_node')
        ->and($payload['error']['meta']['required_role'])->toBe('app-development');
});

it('uses gh cli for github shorthand source creation and registry write', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    $targetNode = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'user' => 'deploy',
        'status' => 'active',
    ]);
    assignAppNewRole($targetNode, 'app-development', settings: ['tld' => 'test']);

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
        ->and($remoteShell->runs[0]['script'])->toContain("gh repo clone 'acme/api' '/home/deploy/apps/api'")
        ->and($remoteShell->runs[0]['script'])->not->toContain('git clone')
        ->and(App::query()->where('name', 'api')->value('repository'))->toBe('git@github.com:acme/api.git')
        ->and($payload['success']['data']['app']['repository'])->toBe('git@github.com:acme/api.git')
        ->and($payload['success']['data']['app']['root'])->toBe('web');
});

it('uses gh cli for github urls', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    $targetNode = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'user' => 'deploy',
        'status' => 'active',
    ]);
    assignAppNewRole($targetNode, 'app-development', settings: ['tld' => 'test']);

    $remoteShell = new RecordingRemoteShell;
    app()->instance(RemoteShell::class, $remoteShell);

    $exitCode = Artisan::call('app:new', [
        'name' => 'api',
        '--node' => 'app-1',
        '--repo' => 'https://github.com/acme/api.git',
        '--json' => true,
    ]);

    expect($exitCode)->toBe(0)
        ->and($remoteShell->runs[0]['script'])->toContain("gh repo clone 'acme/api' '/home/deploy/apps/api'")
        ->and($remoteShell->runs[0]['script'])->not->toContain('git clone')
        ->and(App::query()->where('name', 'api')->value('repository'))->toBe('https://github.com/acme/api.git');
});

it('uses git clone for non-github repositories', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    $targetNode = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'user' => 'deploy',
        'status' => 'active',
    ]);
    assignAppNewRole($targetNode, 'app-development', settings: ['tld' => 'test']);

    $remoteShell = new RecordingRemoteShell;
    app()->instance(RemoteShell::class, $remoteShell);

    $exitCode = Artisan::call('app:new', [
        'name' => 'api',
        '--node' => 'app-1',
        '--repo' => 'https://gitlab.com/acme/api.git',
        '--json' => true,
    ]);

    expect($exitCode)->toBe(0)
        ->and($remoteShell->runs[0]['script'])->toContain("git clone 'https://gitlab.com/acme/api.git' '/home/deploy/apps/api'")
        ->and($remoteShell->runs[0]['script'])->not->toContain('gh repo clone')
        ->and(App::query()->where('name', 'api')->value('repository'))->toBe('https://gitlab.com/acme/api.git');
});

it('does not write gateway app intent when source creation fails', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    $targetNode = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'status' => 'active',
    ]);
    assignAppNewRole($targetNode, 'app-development', settings: ['tld' => 'test']);

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
            'transport' => 'github',
        ]);
});

it('keeps gateway app intent and reports a warning when runtime enactment needs later convergence', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    $targetNode = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'status' => 'active',
    ]);
    assignAppNewRole($targetNode, 'app-development', settings: ['tld' => 'test']);

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
    ]);

    $targetNode = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'status' => 'active',
    ]);
    assignAppNewRole($targetNode, 'app-development', settings: ['tld' => 'test']);

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
    $fpmPool = base64_decode((string) str($remoteShell->scripts[2])->match("/printf %s\\s+'([^']+)'/")->toString(), true);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['meta']['warnings'])->toBe([])
        ->and($remoteShell->scripts[1])->toContain('/usr/sbin/php-fpm8.5')
        ->and($remoteShell->scripts[1])->toContain('php-fpm8.5')
        ->and($remoteShell->scripts[2])->toContain('/etc/php/8.5/fpm/pool.d/orbit-docs.conf')
        ->and($fpmPool)->toContain('[orbit-docs]')
        ->and($fpmPool)->toContain('listen = /home/orbit/.config/orbit/php/docs.sock')
        ->and($fpmPool)->toContain('listen.group = caddy')
        ->and($remoteShell->scripts[2])->toContain("PHP_FPM_SERVICE='php8.5-fpm'")
        ->and($remoteShell->scripts[2])->toContain('sudo rm -f "$ORBIT_STALE_POOL"')
        ->and($remoteShell->scripts[2])->toContain('sudo systemctl restart "$PHP_FPM_SERVICE"');
});

it('records and enacts an app-owned proxy route after app intent is durable', function (): void {
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
    assignAppNewRole($targetNode, 'app-development', settings: ['tld' => 'test']);

    $remoteShell = new SequencedRecordingRemoteShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '/usr/sbin/php-fpm', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);
    $certificates = new SiteCertificateInstallerFake;
    app()->instance(RemoteShell::class, $remoteShell);
    app()->instance(SiteCertificateInstaller::class, $certificates);

    $exitCode = Artisan::call('app:new', [
        'name' => 'docs',
        '--node' => 'app-1',
        '--json' => true,
    ]);

    $route = ProxyRoute::query()->where('domain', 'docs.test')->first();
    $globalCaddyfile = base64_decode((string) str($remoteShell->scripts[4])->match("/printf %s\\s+'([^']+)'/")->toString(), true);
    $caddySite = base64_decode((string) str($remoteShell->scripts[5])->match("/printf %s\\s+'([^']+)'/")->toString(), true);

    expect($exitCode)->toBe(0)
        ->and($route)->not->toBeNull()
        ->and($route?->node_id)->toBe($targetNode->id)
        ->and($route?->owner_type)->toBe('app')
        ->and($route?->kind)->toBe('app')
        ->and($route?->config)->toMatchArray([
            'document_root' => '/home/orbit/apps/docs/public',
            'php_socket' => '/home/orbit/.config/orbit/php/docs.sock',
            'tls' => [
                'cert_path' => '/home/orbit/.config/orbit/certs/docs.test.crt',
                'key_path' => '/home/orbit/.config/orbit/certs/docs.test.key',
            ],
        ])
        ->and($certificates->hosts)->toBe(['docs.test'])
        ->and($remoteShell->scripts[3])->toContain('sudo cat /etc/caddy/Caddyfile')
        ->and($globalCaddyfile)->toContain('import /etc/caddy/sites/*.caddy')
        ->and($globalCaddyfile)->toContain('(security_headers)')
        ->and($remoteShell->scripts[5])->toContain('/etc/caddy/sites/docs.test.caddy')
        ->and($caddySite)->toContain('docs.test {')
        ->and($caddySite)->toContain('tls /home/orbit/.config/orbit/certs/docs.test.crt /home/orbit/.config/orbit/certs/docs.test.key')
        ->and($caddySite)->toContain('root * /home/orbit/apps/docs/public')
        ->and($caddySite)->toContain('php_fastcgi unix//home/orbit/.config/orbit/php/docs.sock')
        ->and($route?->source_hash)->toBe(hash('sha256', $caddySite))
        ->and($remoteShell->scripts[5])->toContain("docker restart 'orbit-caddy'")
        ->and($remoteShell->scripts[5])->not->toContain('caddy reload')
        ->and($remoteShell->scripts[5])->not->toContain('sudo systemctl reload caddy');
});

it('uses the production domain as the app-owned proxy route domain', function (): void {
    $router = Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'wireguard_address' => '10.6.0.2',
    ]);
    assignAppNewRole($router, 'router');

    $targetNode = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'tld' => 'test',
        'status' => 'active',
        'wireguard_address' => '10.6.0.5',
    ]);
    assignAppNewRole($targetNode, 'ingress');
    assignAppNewRole($targetNode, 'app-production', settings: ['ingress_node_id' => $targetNode->id]);

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
    ]);

    $targetNode = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'tld' => 'test',
        'status' => 'active',
    ]);
    assignAppNewRole($targetNode, 'app-development', settings: ['tld' => 'test']);

    app()->instance(RemoteShell::class, new SequencedRecordingRemoteShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '/usr/sbin/php-fpm', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'caddy restart failed', durationMs: 1),
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
    ]);

    $targetNode = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'tld' => 'test',
        'status' => 'active',
    ]);
    assignAppNewRole($targetNode, 'app-development', settings: ['tld' => 'test']);

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
    ]);

    $existingNode = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'status' => 'active',
    ]);
    assignAppNewRole($existingNode, 'app-development', settings: ['tld' => 'test']);

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
    config(['orbit.is_gateway' => false]);

    Node::factory()->create([
        'name' => 'control-1',
        'role' => 'control',
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
