<?php

declare(strict_types=1);

use App\Actions\Workspaces\SetupWorkspace;
use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppInstanceDriver;
use App\Enums\ProcessCrashNotification;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Enums\WorkspaceLifecyclePhase;
use App\Enums\WorkspaceLifecycleStatus;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process as OrbitProcess;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Models\WorkspaceRun;
use App\Models\WorkspaceStep;
use App\Services\Ca\OrbitCaService;
use App\Services\Gateway\CaddyGlobalConfig;
use App\Services\NodeCommandTransport\NodeTransportPreference;
use App\Services\Nodes\NodeHostPaths;
use App\Services\RemoteShell\ExplicitRemoteShellFallback;
use App\Services\Workspaces\EnsureWorkspaceProxyRoute;
use App\Services\Workspaces\WorkspaceSetupTargetResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    DB::table('nodes')->insert([
        [
            'name' => 'gateway',
            'host' => 'gateway',
            'user' => 'gateway',
            'orbit_path' => '/home/gateway/orbit',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    DB::table('node_role')->insert([
        'node_id' => 1,
        'role' => 'gateway',
        'status' => 'active',
        'settings' => json_encode([], JSON_THROW_ON_ERROR),
        'last_error' => null,
        'converged_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('apps')->insert([
        [
            'name' => 'demo',
            'domain' => 'demo.beast',
            'node_id' => 1,
            'path' => '/home/nckrtl/apps/demo',
            'php_version' => '8.5',
            'runtime' => 'php',
            'document_root' => 'public',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    app()->instance(RemoteShell::class, new SetupWorkspaceActionTestShell);
    app()->instance(SiteCertificateInstaller::class, new SetupWorkspaceActionTestCertificateInstaller);
    app()->instance(OrbitCaService::class, new SetupWorkspaceActionTestCa);

    request()->headers->set(ExplicitRemoteShellFallback::HEADER, ExplicitRemoteShellFallback::REQUIRED);
});

afterEach(function (): void {
    request()->headers->remove(ExplicitRemoteShellFallback::HEADER);
});

function setup_workspace_use_agent_push(): void
{
    request()->headers->set(ExplicitRemoteShellFallback::HEADER, NodeTransportPreference::AgentPush->value);
}

it('sets up a workspace and marks it active', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;

    $setup = app(SetupWorkspace::class);
    $result = $setup->handle($app, $workspace, $node);

    expect($result['action'])->toBe('set_up');
    expect($result['workspace'])->toBe('feature-a');
    expect($result['app'])->toBe('demo');

    $workspace->refresh();
    expect($workspace->lifecycle_status)->toBe(WorkspaceLifecycleStatus::Active);
});

it('does not render PHP-FPM pool config for PHP workspaces in the steady-state path', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;
    $shell = new SetupWorkspaceActionTestShell;
    $certificates = new SetupWorkspaceActionTestCertificateInstaller;
    app()->instance(RemoteShell::class, $shell);
    app()->instance(SiteCertificateInstaller::class, $certificates);

    app(SetupWorkspace::class)->handle($app, $workspace, $node);

    expect(collect($shell->scripts)
        ->contains(
            fn (string $script): bool => str_contains($script, '/etc/php/8.5/fpm/pool.d/orbit-demo-feature-a.conf'),
        ))
        ->toBeFalse()
        ->and(collect($shell->scripts)
            ->contains(fn (string $script): bool => str_contains($script, "PHP_FPM_SERVICE='php8.5-fpm'")))
        ->toBeFalse()
        ->and(collect($shell->scripts)
            ->contains(fn (string $script): bool => str_contains($script, 'sudo systemctl restart')))
        ->toBeFalse();
});

it('enacts the FrankenPHP runtime container for PHP workspaces without FPM', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;
    $shell = new SetupWorkspaceActionTestShell;
    $certificates = new SetupWorkspaceActionTestCertificateInstaller;
    app()->instance(RemoteShell::class, $shell);
    app()->instance(SiteCertificateInstaller::class, $certificates);

    app(SetupWorkspace::class)->handle($app, $workspace, $node);

    $runScript = collect($shell->scripts)
        ->first(
            fn (string $script): bool => (
                str_contains($script, 'docker run -d') && str_contains($script, "'orbit-ws-demo-feature-a'")
            ),
        );

    expect($runScript)
        ->toContain('docker run -d')
        ->and($runScript)
        ->toContain("'orbit-ws-demo-feature-a'")
        ->and($runScript)
        ->toContain("'ghcr.io/hardimpactdev/orbit-frankenphp:1-php8.5-bookworm'")
        ->and($runScript)
        ->toContain('/home/gateway/.config/orbit/workspaces/demo-feature-a.ini')
        ->and(collect($shell->scripts)
            ->contains(
                fn (string $script): bool => str_contains(
                    $script,
                    "docker image inspect 'ghcr.io/hardimpactdev/orbit-frankenphp:1-php8.5-bookworm'",
                ),
            ))
        ->toBeTrue()
        ->and(collect($shell->scripts)
            ->contains(
                fn (string $script): bool => str_contains($script, '/etc/php/8.5/fpm/pool.d/orbit-demo-feature-a.conf'),
            ))
        ->toBeFalse();

    expectWorkspaceFrankenPhpRuntimeProcess($workspace);
});

it('reconciles an existing FrankenPHP workspace runtime process row', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    OrbitProcess::factory()
        ->forOwner($workspace)
        ->create([
            'name' => 'frankenphp-demo-feature-a',
            'command' => 'stale command',
            'restart_policy' => ProcessRestartPolicy::Never,
            'crash_notification' => ProcessCrashNotification::AgentIde,
            'runtime' => ProcessRuntime::Systemd,
            'runtime_config' => [
                'container_name' => 'stale-container',
                'php_ini_path' => '/stale.ini',
            ],
        ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;

    app(SetupWorkspace::class)->handle($app, $workspace, $node);

    expectWorkspaceFrankenPhpRuntimeProcess($workspace);
});

it('registers workspace proxy routes against the FrankenPHP runtime container', function (): void {
    setup_workspace_use_agent_push();

    NodeRoleAssignment::query()
        ->where('node_id', 1)
        ->where('role', 'gateway')
        ->update(['role' => 'app-dev']);
    Node::query()
        ->whereKey(1)
        ->update([
            'orbit_agent_capable' => true,
            'wireguard_address' => '10.47.0.41',
        ]);

    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;
    $shell = new SetupWorkspaceActionTestShell;
    $certificates = new SetupWorkspaceActionTestCertificateInstaller;
    app()->instance(RemoteShell::class, $shell);
    app()->instance(SiteCertificateInstaller::class, $certificates);
    Http::preventStrayRequests();
    Http::fake([
        'http://10.47.0.41:9477/v1/commands' => Http::sequence()
            ->push(setup_workspace_agent_response('caddy-config.read-global', [
                'content' => new CaddyGlobalConfig()->fresh(),
            ]))
            ->push(setup_workspace_agent_response('caddy-config.write-site', [
                'path' => '/etc/caddy/sites/feature-a.demo.caddy',
            ]))
            ->push(setup_workspace_agent_response('caddy-config.reload', [
                'container' => 'orbit-caddy',
            ])),
    ]);

    app(EnsureWorkspaceProxyRoute::class)->handle($workspace);

    $requests = setup_workspace_agent_requests('10.47.0.41');
    $sitePayload = json_decode((string) ($requests[1]['input'] ?? ''), associative: true, flags: JSON_THROW_ON_ERROR);
    $caddySite = (string) ($sitePayload['content'] ?? '');
    $route = $workspace->proxyRoutes()->first();

    expect($caddySite)
        ->toContain(
            'tls /home/gateway/.config/orbit/certs/feature-a.demo.crt /home/gateway/.config/orbit/certs/feature-a.demo.key',
        )
        ->and($caddySite)
        ->toContain('reverse_proxy http://orbit-ws-demo-feature-a')
        ->and($caddySite)
        ->not->toContain('tls_trust_pool file /etc/orbit/ca/root.crt')->and($caddySite)
        ->not->toContain('tls_server_name feature-a.demo')->and($caddySite)
        ->not->toContain('php_fastcgi')->and($route?->config['runtime_upstream'])->toBe(
            'http://orbit-ws-demo-feature-a',
        )->and($requests)->toHaveCount(3)->and($requests[0]['argv'][1] ?? null)->toBe('read-global')->and(
            $requests[1]['argv'][1] ?? null,
        )->toBe('write-site')->and($sitePayload)->toMatchArray(['domain' => 'feature-a.demo'])->and(
            $requests[2]['argv'][1] ?? null,
        )->toBe('reload')->and($route?->config['runtime_upstream_tls'] ?? null)->toBeNull()->and(
            $route?->config['php_socket'],
        )->toBeNull()->and($route?->config['tls'])->toBe([
            'cert_path' => '/home/gateway/.config/orbit/certs/feature-a.demo.crt',
            'key_path' => '/home/gateway/.config/orbit/certs/feature-a.demo.key',
        ])->and($certificates->hosts)->toBe(['feature-a.demo'])->and($route?->source_hash)->toBe(hash(
            'sha256',
            $caddySite,
        ));
});

it('sets up a Codex worktree against the selected app instance node', function (): void {
    $canonicalNode = Node::query()->findOrFail(1);
    $canonicalNode->update([
        'name' => 'beast',
        'host' => 'beast',
        'tld' => 'test',
    ]);

    $localNode = Node::factory()->create([
        'name' => 'NMBP',
        'host' => 'nmbp',
        'user' => 'nckrtl',
        'platform' => 'macos',
        'tld' => 'nmbp',
        'status' => 'active',
        'wireguard_address' => '10.47.0.55',
    ]);
    NodeRoleAssignment::factory()
        ->for($localNode, 'node')
        ->create([
            'role' => 'app-dev',
            'status' => 'active',
        ]);

    $app = App::query()->firstOrFail();
    $app->update([
        'name' => 'happie',
        'domain' => 'happie.test',
        'path' => '/home/nckrtl/apps/happie',
        'node_id' => $canonicalNode->id,
    ]);
    $app->refresh();

    $instance = AppInstance::factory()
        ->for($app)
        ->create([
            'name' => 'nmbp',
            'driver' => AppInstanceDriver::Orbit,
            'driver_config' => new OrbitAppInstanceDriverConfigData(
                node_id: $localNode->id,
                node: 'NMBP',
                path: '/Users/nckrtl/apps/happie',
                document_root: 'public',
                domain: 'happie.nmbp',
            ),
        ]);

    $shell = new SetupWorkspaceActionTestShell;
    $certificates = new SetupWorkspaceActionTestCertificateInstaller;
    app()->instance(RemoteShell::class, $shell);
    app()->instance(SiteCertificateInstaller::class, $certificates);

    [$workspace, $resolvedApp, $resolvedNode, $isAdoption] = app(WorkspaceSetupTargetResolver::class)->resolve(
        name: 'recipes',
        appName: 'happie.nmbp',
        path: '/Users/nckrtl/.codex/worktrees/a59f/happie',
        callerCwd: null,
        callerNode: $localNode,
    );

    expect($resolvedApp->is($app))
        ->toBeTrue()
        ->and($resolvedNode->is($localNode))
        ->toBeTrue()
        ->and($workspace->app_instance_id)
        ->toBe($instance->id)
        ->and($workspace->url())
        ->toBe('https://recipes.happie.nmbp')
        ->and($isAdoption)
        ->toBeTrue();

    $result = app(SetupWorkspace::class)->handle($resolvedApp, $workspace, $resolvedNode, $isAdoption);
    $workspace->refresh();

    expect($result['node'])
        ->toBe('NMBP')
        ->and($result['url'])
        ->toBe('https://recipes.happie.nmbp')
        ->and($workspace->lifecycle_status)
        ->toBe(WorkspaceLifecycleStatus::Active)
        ->and($workspace->proxyRoutes()->where('domain', 'recipes.happie.nmbp')->exists())
        ->toBeTrue()
        ->and($shell->runs)
        ->each(fn (Pest\Expectation $run) => $run->node->toBe($localNode->id))
        ->and($certificates->hosts)
        ->toContain('recipes.happie.nmbp');

    expectWorkspaceFrankenPhpRuntimeProcess($workspace, $localNode->id);
});

it('installs workspace app-dev runtime trust pool through the managed file agent path', function (): void {
    setup_workspace_use_agent_push();

    NodeRoleAssignment::query()
        ->where('node_id', 1)
        ->where('role', 'gateway')
        ->update(['role' => 'app-dev']);
    Node::query()
        ->whereKey(1)
        ->update([
            'orbit_agent_capable' => true,
            'wireguard_address' => '10.47.0.42',
        ]);
    App::query()
        ->findOrFail(1)
        ->update([
            'runtime_config' => ['proxy_transport' => 'https'],
        ]);

    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    app()->instance(RemoteShell::class, new SetupWorkspaceActionTestShell);
    app()->instance(SiteCertificateInstaller::class, new SetupWorkspaceActionTestCertificateInstaller);
    Http::preventStrayRequests();
    Http::fake([
        'http://10.47.0.42:9477/v1/commands' => Http::sequence()
            ->push(setup_workspace_agent_response('managed-file.probe', [
                'exists' => false,
                'hash' => null,
                'mode' => null,
            ]))
            ->push(setup_workspace_agent_response('managed-file.write', [
                'path' => '/etc/orbit/ca/root.crt',
                'hash' => hash(algo: 'sha256', data: 'fake-root-ca'),
                'mode' => '0644',
            ]))
            ->push(setup_workspace_agent_response('caddy-config.read-global', [
                'content' => new CaddyGlobalConfig()->fresh(),
            ]))
            ->push(setup_workspace_agent_response('caddy-config.write-site', [
                'path' => '/etc/caddy/sites/feature-a.demo.caddy',
            ]))
            ->push(setup_workspace_agent_response('caddy-config.reload', [
                'container' => 'orbit-caddy',
            ])),
    ]);

    app(EnsureWorkspaceProxyRoute::class)->handle($workspace);

    $requests = setup_workspace_agent_requests('10.47.0.42');
    $managedFilePayload = json_decode(
        (string) ($requests[1]['input'] ?? ''),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );
    $sitePayload = json_decode((string) ($requests[3]['input'] ?? ''), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($requests)
        ->toHaveCount(5)
        ->and(array_slice($requests[0]['argv'] ?? [], offset: 0, length: 2))
        ->toBe(['internal:managed-file', 'probe'])
        ->and(array_slice($requests[1]['argv'] ?? [], offset: 0, length: 2))
        ->toBe(['internal:managed-file', 'write'])
        ->and($managedFilePayload)
        ->toMatchArray([
            'path' => '/etc/orbit/ca/root.crt',
            'content' => 'fake-root-ca',
            'mode' => '0644',
            'directory_mode' => '0755',
        ])
        ->and(array_slice($requests[3]['argv'] ?? [], offset: 0, length: 2))
        ->toBe(['internal:caddy-config', 'write-site'])
        ->and((string) ($sitePayload['content'] ?? ''))
        ->toContain('tls_trust_pool file /etc/orbit/ca/root.crt')
        ->toContain('tls_server_name feature-a.demo');
});

it('registers production workspace routes on ingress with a private backend site', function (): void {
    setup_workspace_use_agent_push();

    $appHost = Node::query()->whereKey(1)->firstOrFail();
    NodeRoleAssignment::query()
        ->where('node_id', $appHost->id)
        ->where('role', 'gateway')
        ->delete();

    $edge = Node::factory()->create([
        'name' => 'edge-1',
        'status' => 'active',
        'user' => 'orbit',
    ]);

    $router = Node::factory()->create([
        'name' => 'gateway-1',
        'status' => 'active',
        'wireguard_address' => '10.6.0.2',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $edge->id,
        'role' => 'ingress',
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $router->id,
        'role' => 'router',
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $appHost->id,
        'role' => 'app-prod',
        'status' => 'active',
        'settings' => ['ingress_node_id' => $edge->id],
    ]);

    App::query()
        ->whereKey(1)
        ->update([
            'domain' => 'demo.example.com',
            'environment' => 'production',
        ]);

    Node::query()
        ->whereKey($appHost->id)
        ->update([
            'wireguard_address' => '10.6.0.21',
            'user' => 'orbit',
            'orbit_agent_capable' => true,
        ]);
    Node::query()
        ->whereKey($edge->id)
        ->update([
            'orbit_agent_capable' => true,
            'wireguard_address' => '10.6.0.31',
        ]);
    Node::query()
        ->whereKey($router->id)
        ->update([
            'orbit_agent_capable' => true,
        ]);

    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    $app = App::query()->with('node')->firstOrFail();
    $node = $app->node;
    $shell = new SetupWorkspaceActionTestShell;
    $certificates = new SetupWorkspaceActionTestCertificateInstaller;
    app()->instance(RemoteShell::class, $shell);
    app()->instance(SiteCertificateInstaller::class, $certificates);
    Http::preventStrayRequests();
    Http::fake([
        'http://10.6.0.31:9477/v1/commands' => setup_workspace_caddy_sequence(
            '/etc/caddy/sites/feature-a.demo.example.com.caddy',
        ),
        'http://10.6.0.2:9477/v1/commands' => setup_workspace_caddy_sequence(
            '/etc/caddy/sites/feature-a.demo.example.com.caddy',
        ),
        'http://10.6.0.21:9477/v1/commands' => setup_workspace_caddy_sequence(
            '/etc/caddy/sites/feature-a.demo.example.com.backend.caddy',
        ),
    ]);

    app(EnsureWorkspaceProxyRoute::class)->handle($workspace);

    $route = ProxyRoute::query()->where('workspace_id', $workspace->id)->firstOrFail();

    expect($route->node_id)
        ->toBe($edge->id)
        ->and($route->config['placement'])
        ->toBe('ingress')
        ->and($route->config['router_upstream'])
        ->toBe([
            'node_id' => $router->id,
            'node' => 'gateway-1',
            'url' => 'http://10.6.0.2:80',
        ])
        ->and($route->config['router_artifact']['node_id'])
        ->toBe($router->id)
        ->and($route->config['router_artifact']['source_hash'])
        ->toHaveLength(64)
        ->and($route->config['router_backend_pool'])
        ->toBe([
            [
                'node_id' => $appHost->id,
                'node' => 'gateway',
                'url' => 'http://10.6.0.21:8081',
            ],
        ])
        ->and($route->config['backend_artifacts'][0]['bind'])
        ->toBe('10.6.0.21')
        ->and($route->config['backend_artifacts'][0]['source_hash'])
        ->toHaveLength(64)
        ->and(setup_workspace_agent_requests('10.6.0.31'))
        ->toHaveCount(3)
        ->and(setup_workspace_agent_requests('10.6.0.2'))
        ->toHaveCount(3)
        ->and(setup_workspace_agent_requests('10.6.0.21'))
        ->toHaveCount(3)
        ->and(setup_workspace_agent_site_payload('10.6.0.31')['domain'] ?? null)
        ->toBe('feature-a.demo.example.com')
        ->and(setup_workspace_agent_site_payload('10.6.0.21')['backend'] ?? null)
        ->toBeTrue()
        ->and((string) (setup_workspace_agent_site_payload('10.6.0.21')['content'] ?? ''))
        ->toContain('reverse_proxy http://orbit-ws-demo-feature-a')
        ->and($certificates->hosts)
        ->toBe(['feature-a.demo.example.com']);
});

it('starts configured app processes for the workspace after rendering runtime units', function (): void {
    setup_workspace_use_agent_push();

    Node::query()
        ->whereKey(1)
        ->update([
            'orbit_agent_capable' => true,
            'wireguard_address' => '10.47.0.45',
        ]);

    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    $app = App::query()->with('node')->firstOrFail();

    OrbitProcess::factory()
        ->forOwner($app)
        ->create([
            'name' => 'vite',
            'command' => 'npm run dev -- --host=0.0.0.0',
            'restart_policy' => 'always',
            'crash_notification' => 'none',
            'sort_order' => 1,
        ]);

    $node = $app->node;
    $shell = new SetupWorkspaceActionTestShell;
    $certificates = new SetupWorkspaceActionTestCertificateInstaller;
    app()->instance(RemoteShell::class, $shell);
    app()->instance(SiteCertificateInstaller::class, $certificates);
    Http::preventStrayRequests();
    Http::fake([
        'http://10.47.0.45:9477/v1/commands' => Http::sequence()
            ->push(setup_workspace_agent_response('caddy-config.read-global', [
                'content' => new CaddyGlobalConfig()->fresh(),
            ]))
            ->push(setup_workspace_agent_response('caddy-config.write-site', [
                'path' => '/etc/caddy/sites/feature-a.demo.caddy',
            ]))
            ->push(setup_workspace_agent_response('caddy-config.reload', [
                'container' => 'orbit-caddy',
            ]))
            ->push(setup_workspace_agent_response('process.systemd.apply', [
                'status' => 'changed',
                'summary' => 'Applied systemd service orbit_demo_feature-a_vite.service.',
            ]))
            ->push(setup_workspace_agent_response('process.systemd.start', [
                'service' => 'orbit_demo_feature-a_vite.service',
            ])),
    ]);

    $result = app(SetupWorkspace::class)->handle($app, $workspace, $node);
    $requests = setup_workspace_agent_requests('10.47.0.45');

    expect($result['processes'])
        ->toMatchArray([
            'status' => 'started',
            'count' => 1,
            'names' => ['vite'],
        ])
        ->and($requests)
        ->toHaveCount(5)
        ->and(array_slice($requests[3]['argv'] ?? [], offset: 0, length: 3))
        ->toBe(['internal:process-systemd-service', 'apply', 'orbit_demo_feature-a_vite.service'])
        ->and(array_slice($requests[4]['argv'] ?? [], offset: 0, length: 3))
        ->toBe(['internal:process-systemd-service', 'start', 'orbit_demo_feature-a_vite.service'])
        ->and($shell->scripts)
        ->not
        ->toContain("sudo systemctl start 'orbit_demo_feature-a_vite.service'")
        ->and(array_values(array_unique($certificates->hosts)))
        ->toBe(['feature-a.demo']);
});

it('reports converged for already-active workspace', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::Active,
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;

    $setup = app(SetupWorkspace::class);
    $result = $setup->handle($app, $workspace, $node);

    expect($result['action'])->toBe('converged');
});

it('reports adopted for new workspace with adoption flag', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;

    $setup = app(SetupWorkspace::class);
    $result = $setup->handle($app, $workspace, $node, isAdoption: true);

    expect($result['action'])->toBe('adopted');
});

it('skips setup steps when none are configured', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;

    $setup = app(SetupWorkspace::class);
    $result = $setup->handle($app, $workspace, $node);

    expect($result['setup_steps']['status'])->toBe('skipped');
    expect($result['setup_steps']['count'])->toBe(0);
});

it('runs setup steps when configured', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    WorkspaceStep::create([
        'app_id' => 1,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'sort_order' => 1,
        'command' => 'echo "hello"',
        'timeout_seconds' => 60,
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;

    $setup = app(SetupWorkspace::class);
    $result = $setup->handle($app, $workspace, $node);

    expect($result['setup_steps']['status'])->toBe('completed');
    expect($result['setup_steps']['count'])->toBe(1);

    $run = WorkspaceRun::query()
        ->where('workspace_id', $workspace->id)
        ->first();

    expect($run)->not->toBeNull();
    expect($run->status)->toBe('completed');
});

it('reports progress while setup steps are running', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    WorkspaceStep::create([
        'app_id' => 1,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'sort_order' => 1,
        'command' => 'composer install --no-interaction',
        'timeout_seconds' => 1200,
    ]);

    WorkspaceStep::create([
        'app_id' => 1,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'sort_order' => 2,
        'command' => 'npm ci',
        'timeout_seconds' => 900,
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;
    $events = [];

    app(SetupWorkspace::class)->runSetupSteps(
        $workspace,
        $app,
        $node,
        function (string $event, WorkspaceStep $step, int $index, int $count) use (&$events): void {
            $events[] = [$event, $step->command, $index, $count];
        },
    );

    expect($events)->toBe([
        ['running',   'composer install --no-interaction', 1, 2],
        ['completed', 'composer install --no-interaction', 1, 2],
        ['running',   'npm ci',                            2, 2],
        ['completed', 'npm ci',                            2, 2],
    ]);
});

it('routes php and composer setup steps through the selected workspace host php toolchain', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    WorkspaceStep::create([
        'app_id' => 1,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'sort_order' => 1,
        'command' => 'composer install --no-interaction',
        'timeout_seconds' => 1200,
    ]);

    WorkspaceStep::create([
        'app_id' => 1,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'sort_order' => 2,
        'command' => 'php artisan migrate --force',
        'timeout_seconds' => 300,
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;
    $shell = new SetupWorkspaceActionTestShell;
    app()->instance(RemoteShell::class, $shell);

    app(SetupWorkspace::class)->handle($app, $workspace, $node);

    $stepRuns = array_values(array_filter(
        $shell->runs,
        fn (array $run): bool => (
            str_contains($run['script'], 'composer install') || str_contains($run['script'], 'php artisan')
        ),
    ));

    expect($stepRuns)->toHaveCount(2);

    expect($stepRuns[0]['script'])
        ->toContain("'sudo'")
        ->toContain("'-u'")
        ->toContain("'gateway'")
        ->toContain('/opt/orbit/php/')
        ->toContain('/home/nckrtl/apps/demo/.worktrees/feature-a')
        ->toContain('composer install --no-interaction');
    expect($stepRuns[0]['options']['cwd'] ?? null)->toBe('/home/nckrtl/apps/demo/.worktrees/feature-a');

    expect($stepRuns[1]['script'])
        ->toContain("'sudo'")
        ->toContain('/opt/orbit/php/')
        ->toContain('/home/nckrtl/apps/demo/.worktrees/feature-a')
        ->toContain('php artisan migrate --force');
    expect($stepRuns[1]['options']['cwd'] ?? null)->toBe('/home/nckrtl/apps/demo/.worktrees/feature-a');
});

it('keeps non-php setup steps on the host', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    WorkspaceStep::create([
        'app_id' => 1,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'sort_order' => 1,
        'command' => 'npm ci',
        'timeout_seconds' => 900,
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;
    $shell = new SetupWorkspaceActionTestShell;
    app()->instance(RemoteShell::class, $shell);

    app(SetupWorkspace::class)->handle($app, $workspace, $node);

    $npmRun = collect($shell->runs)
        ->first(fn (array $run): bool => str_contains($run['script'], 'npm ci'));

    expect($npmRun['script'])->not->toContain("'docker'");
    expect($npmRun['options']['cwd'] ?? null)->toBe('/home/nckrtl/apps/demo/.worktrees/feature-a');
});

it('passes lifecycle environment into host-routed setup steps', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    WorkspaceStep::create([
        'app_id' => 1,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'sort_order' => 1,
        'command' => 'composer install',
        'timeout_seconds' => 1200,
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;
    $shell = new SetupWorkspaceActionTestShell;
    app()->instance(RemoteShell::class, $shell);

    app(SetupWorkspace::class)->handle($app, $workspace, $node);

    $composerRun = collect($shell->runs)
        ->first(fn (array $run): bool => str_contains($run['script'], 'composer install'));

    $workspaceHost = 'feature-a.demo';
    $workspaceUrl = "https://{$workspaceHost}";

    expect($composerRun['script'])->toContain('ORBIT_APP=');
    expect($composerRun['script'])->toContain('demo');
    expect($composerRun['script'])->toContain('ORBIT_WORKSPACE_NAME=');
    expect($composerRun['script'])->toContain('feature-a');
    expect($composerRun['script'])->toContain('ORBIT_URL=');
    expect($composerRun['script'])->toContain($workspaceUrl);
    expect($composerRun['script'])->toContain('APP_URL=');
    expect($composerRun['script'])->toContain('VITE_APP_URL=');
    expect($composerRun['script'])
        ->toContain('VITE_DEV_SERVER_KEY=')
        ->toContain("/home/gateway/.config/orbit/certs/{$workspaceHost}.key");
    expect($composerRun['script'])
        ->toContain('VITE_DEV_SERVER_CERT=')
        ->toContain("/home/gateway/.config/orbit/certs/{$workspaceHost}.crt");
    expect($composerRun['options']['metadata'])->toMatchArray([
        'APP_URL' => $workspaceUrl,
        'VITE_APP_URL' => $workspaceUrl,
        'VITE_VALET_HOST' => $workspaceHost,
        'VITE_DEV_SERVER_KEY' => "/home/gateway/.config/orbit/certs/{$workspaceHost}.key",
        'VITE_DEV_SERVER_CERT' => "/home/gateway/.config/orbit/certs/{$workspaceHost}.crt",
    ]);
});

it('skips setup steps when hash matches previous successful run', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    WorkspaceStep::create([
        'app_id' => 1,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'sort_order' => 1,
        'command' => 'echo "hello"',
        'timeout_seconds' => 60,
    ]);

    WorkspaceRun::create([
        'workspace_id' => $workspace->id,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'status' => 'completed',
        'step_set_hash' => hash('sha256', json_encode([
            ['command' => 'echo "hello"', 'timeout' => 60],
        ])),
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;

    $setup = app(SetupWorkspace::class);
    $result = $setup->handle($app, $workspace, $node);

    expect($result['setup_steps']['status'])->toBe('skipped');
    expect($result['setup_steps']['message'])->toBe('Already up to date');
});

it('throws when setup step fails', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    WorkspaceStep::create([
        'app_id' => 1,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'sort_order' => 1,
        'command' => 'exit 1',
        'timeout_seconds' => 60,
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;

    app()->instance(RemoteShell::class, new SetupWorkspaceActionFailingShell);

    $setup = app(SetupWorkspace::class);

    expect(fn () => $setup->handle($app, $workspace, $node))
        ->toThrow(RuntimeException::class, 'Setup step failed: exit 1');

    $workspace->refresh();
    expect($workspace->lifecycle_status)->toBe(WorkspaceLifecycleStatus::SettingUp);
});

final class SetupWorkspaceActionTestShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @var list<array{node: int|null, script: string, options: array<string, mixed>}>
     */
    public array $runs = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->runs[] = [
            'node' => $node->id,
            'script' => $script,
            'options' => $options,
        ];
        $this->scripts[] = $script;

        if (str_contains($script, 'sudo systemctl is-enabled "$service"')) {
            return new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode([
                    'exists' => false,
                    'hash' => null,
                    'enabled' => false,
                ], JSON_THROW_ON_ERROR)
                    ."\n",
                stderr: '',
                durationMs: 1,
            );
        }

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

final class SetupWorkspaceActionFailingShell implements RemoteShell
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'step failed', durationMs: 1);
    }
}

final class SetupWorkspaceActionTestCertificateInstaller implements SiteCertificateInstaller
{
    /**
     * @var list<string>
     */
    public array $hosts = [];

    public function ensureFor(Node $node, string $host): array
    {
        $this->hosts[] = $host;

        return $this->expectedPathsFor($node, $host);
    }

    public function expectedPathsFor(Node $node, string $host): array
    {
        return [
            'cert' => "/home/gateway/.config/orbit/certs/{$host}.crt",
            'key' => "/home/gateway/.config/orbit/certs/{$host}.key",
        ];
    }
}

final readonly class SetupWorkspaceActionTestCa extends OrbitCaService
{
    public function rootCert(): string
    {
        return 'fake-root-ca';
    }
}

function expectWorkspaceFrankenPhpRuntimeProcess(Workspace $workspace, ?int $expectedNodeId = null): void
{
    $workspace->loadMissing('app');
    $expectedNodeId ??= $workspace->app->node_id;
    $node = Node::query()->findOrFail($expectedNodeId);
    $home = NodeHostPaths::homeDirectoryFor($node->platform, $node->user);

    $process = OrbitProcess::query()
        ->ownedBy($workspace)
        ->where('name', "frankenphp-{$workspace->app->name}-{$workspace->name}")
        ->first();

    expect($process)
        ->not
        ->toBeNull()
        ->and($process?->node_id)
        ->toBe($expectedNodeId)
        ->and($process?->command)
        ->toBe('frankenphp')
        ->and($process?->restart_policy)
        ->toBe(ProcessRestartPolicy::Always)
        ->and($process?->crash_notification)
        ->toBe(ProcessCrashNotification::None)
        ->and($process?->runtime)
        ->toBe(ProcessRuntime::Docker)
        ->and($process?->tool)
        ->toBeNull()
        ->and($process?->runtime_config)
        ->toMatchArray([
            'container_name' => "orbit-ws-{$workspace->app->name}-{$workspace->name}",
            'php_ini_path' => "{$home}/.config/orbit/workspaces/{$workspace->app->name}-{$workspace->name}.ini",
            'container_spec_hash_label' => 'orbit.workspace.spec_hash',
        ]);
}

function setup_workspace_caddy_sequence(string $sitePath): \Illuminate\Http\Client\ResponseSequence
{
    return Http::sequence()
        ->push(setup_workspace_agent_response('caddy-config.read-global', [
            'content' => new CaddyGlobalConfig()->fresh(),
        ]))
        ->push(setup_workspace_agent_response('caddy-config.write-site', [
            'path' => $sitePath,
        ]))
        ->push(setup_workspace_agent_response('caddy-config.reload', [
            'container' => 'orbit-caddy',
        ]));
}

/**
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function setup_workspace_agent_response(string $operationId, array $data, int $exitCode = 0): array
{
    return [
        'transport' => 'agent-push',
        'operation_id' => $operationId,
        'binary' => 'orbit',
        'status' => $exitCode === 0 ? 'succeeded' : 'failed',
        'exit_code' => $exitCode,
        'frames' => [
            [
                'type' => 'stdout',
                'message' => json_encode([
                    'success' => [
                        'data' => $data,
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
            [
                'type' => 'exit',
                'message' => (string) $exitCode,
            ],
        ],
    ];
}

/**
 * @return list<Request>
 */
function setup_workspace_agent_requests(string $wireguardAddress): array
{
    return Http::recorded(
        fn (Request $request): bool => $request->url() === "http://{$wireguardAddress}:9477/v1/commands",
    )
        ->map(fn (array $record): Request => $record[0])
        ->values()
        ->all();
}

/**
 * @return array<string, mixed>
 */
function setup_workspace_agent_site_payload(string $wireguardAddress): array
{
    foreach (setup_workspace_agent_requests($wireguardAddress) as $request) {
        if (($request['argv'][1] ?? null) !== 'write-site') {
            continue;
        }

        /** @var array<string, mixed> */
        return json_decode((string) ($request['input'] ?? ''), associative: true, flags: JSON_THROW_ON_ERROR);
    }

    return [];
}
