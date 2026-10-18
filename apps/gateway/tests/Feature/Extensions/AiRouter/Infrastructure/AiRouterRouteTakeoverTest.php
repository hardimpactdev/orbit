<?php

declare(strict_types=1);

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Certificates\GatewayCertificatePaths;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteRemovalProjector;
use App\Domain\Routes\RouteStatus;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildResult;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Nodes\CaddyPackageProgram;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\DatabaseConnection;
use App\Models\Node;
use App\Models\Process;
use App\Models\Route;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\AiRouter\Actions\ConvergeAiRouterAction;
use Orbit\Extensions\AiRouter\Domain\AiRouterCache;
use Orbit\Extensions\AiRouter\Domain\AiRouterPublicationManager;
use Orbit\Extensions\AiRouter\Domain\AiRouterRuntimeLifecycle;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterCertificatePublisher;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterRenameCutover;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterRouteTakeover;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterSourcePublisher;
use Orbit\Extensions\AiRouter\Infrastructure\NativeAiRouterPublicationManager;
use Orbit\Extensions\AiRouter\Infrastructure\NativeAiRouterRuntimeLifecycle;
use Tests\Support\FakeRouteRemovalProjector;
use Tests\Support\ProcessesApiFakeRuntimeManager;

beforeEach(function (): void {
    $this->removal = new FakeRouteRemovalProjector;
    app()->instance(RouteRemovalProjector::class, $this->removal);
    $this->node = Node::query()->create([
        'name' => 'services',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.17',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.17',
    ]);
    $this->management = ai_router_takeover_route($this->node, 'cli-proxy-api.orbit', 'http://127.0.0.1:8317');
    $this->collector = ai_router_takeover_route($this->node, 'collector.ai-router.orbit', 'http://127.0.0.1:8787');
    app(AiRouterState::class)->enable($this->node->id, 'cache', 'http://127.0.0.1:8317', 'management', 'read', 'control');
});

it('swaps the Route site for the collector site in one Node Caddy build, then removes the Route', function (): void {
    $events = [];
    $manager = ai_router_takeover_manager($events);

    $manager->converge($this->node, takeover: $this->collector);

    $builds = collect($events)->where('event', 'build')->values();

    expect(collect($events)->pluck('event')->all())
        ->toBe(['ssh:caddy-source', 'certificate:issue', 'ssh:certificate', 'build', 'dns:converge'])
        ->and($builds)->toHaveCount(1)
        ->and($builds[0]['caddyfile'])->toContain('# orbit: ai-router collector.ai-router.orbit')
        ->toContain('https://cli-proxy-api.orbit {')
        ->not->toContain('https://collector.ai-router.orbit {')
        ->and($builds[0]['collector_route'])->toBe(['status' => RouteStatus::Retiring, 'sites_published' => false])
        ->and($this->removal->events)->toBe(['dns', 'caddy', 'certificates', 'firewall'])
        ->and($this->removal->routeIds)->each->toBe($this->collector->id)
        ->and(Route::query()->whereKey($this->collector->id)->exists())->toBeFalse()
        ->and(Route::query()->whereKey($this->management->id)->exists())->toBeTrue();
});

it('restores the Route and keeps it when the build fails', function (): void {
    $events = [];
    $manager = ai_router_takeover_manager($events, failBuild: true);

    expect(fn () => $manager->converge($this->node, takeover: $this->collector))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('ai_router.caddy_publication_failed'));

    $route = $this->collector->refresh();

    expect(collect($events)->pluck('event')->all())
        ->toBe(['ssh:caddy-source', 'certificate:issue', 'ssh:certificate', 'build'])
        ->and($route->status)->toBe(RouteStatus::Active)
        ->and($route->sites_published)->toBeTrue()
        ->and($this->removal->events)->toBe([]);
});

it('refuses a Route that stopped matching before the takeover and changes nothing', function (): void {
    $events = [];
    $manager = ai_router_takeover_manager($events);
    $this->collector->customProxy()->update(['upstream' => 'http://127.0.0.1:9000']);

    expect(fn () => $manager->converge($this->node, takeover: $this->collector))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('ai_router.hostname_taken'));

    expect(collect($events)->pluck('event')->all())->not->toContain('build')
        ->and($this->collector->refresh()->status)->toBe(RouteStatus::Active)
        ->and($this->removal->events)->toBe([]);
});

it('builds the Node once without a takeover when no Route holds the name', function (): void {
    $events = [];
    $manager = ai_router_takeover_manager($events);
    $this->collector->delete();

    $manager->converge($this->node);

    $builds = collect($events)->where('event', 'build')->values();

    expect($builds)->toHaveCount(1)
        ->and($builds[0]['caddyfile'])->toContain('# orbit: ai-router collector.ai-router.orbit')
        ->and($this->removal->events)->toBe([]);
});

function ai_router_takeover_route(Node $node, string $domain, string $upstream): Route
{
    $route = Route::query()->create([
        'kind' => RouteKind::CustomProxy,
        'node_id' => $node->id,
        'domain' => $domain,
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->customProxy()->create(['node_id' => $node->id, 'upstream' => $upstream]);
    $route->update(['status' => RouteStatus::Active]);

    return $route->refresh();
}

/**
 * @param  list<array<string, mixed>>  $events
 */
function ai_router_takeover_manager(array &$events, bool $failBuild = false): NativeAiRouterPublicationManager
{
    $certificateDirectory = sys_get_temp_dir().'/orbit-ai-router-takeover-'.bin2hex(random_bytes(4));
    mkdir($certificateDirectory);
    file_put_contents($certificateDirectory.'/ai_router.pem', "CERT\n");
    file_put_contents($certificateDirectory.'/ai_router.key', "KEY\n");

    return new NativeAiRouterPublicationManager(
        certificates: new class($events, $certificateDirectory) implements GatewayCertificateIssuer
        {
            public function __construct(private array &$events, private string $directory) {}

            public function issue(string $hostname, string $wireguardIp): GatewayCertificatePaths
            {
                $this->events[] = ['event' => 'certificate:issue'];

                return new GatewayCertificatePaths(
                    privateKeyPath: $this->directory.'/ai_router.key',
                    certificatePath: $this->directory.'/ai_router.pem',
                );
            }
        },
        certificatePublisher: new AiRouterCertificatePublisher,
        builds: new class($events, $failBuild) implements NodeCaddyBuilds
        {
            public function __construct(private array &$events, private bool $fail) {}

            public function build(Node $node): NodeCaddyBuildResult
            {
                $route = Route::query()->where('domain', 'collector.ai-router.orbit')->first();
                $caddyfile = app(NodeCaddyfileRenderer::class)->render($node);
                $this->events[] = [
                    'event' => 'build',
                    'caddyfile' => $caddyfile->content,
                    'problems' => $caddyfile->problems,
                    'collector_route' => $route instanceof Route
                        ? ['status' => $route->status, 'sites_published' => $route->sites_published]
                        : null,
                ];

                if ($this->fail || ! $caddyfile->buildable()) {
                    throw new NodeCaddyBuildException($node->name, 'validate', implode(' ', $caddyfile->problems) ?: 'Error: test failure');
                }

                return NodeCaddyBuildResult::Published;
            }

            public function checkListenAddresses(Node $node): void {}
        },
        dns: new class($events) implements PrivateDnsManager
        {
            public function __construct(private array &$events) {}

            public function converge(?Node $pendingNode = null): void
            {
                $this->events[] = ['event' => 'dns:converge'];
            }
        },
        ssh: new class($events) implements SshExecutor
        {
            public function __construct(private array &$events) {}

            public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
            {
                if (in_array(CaddyPackageProgram::RELEASE_URL, $command->arguments, true)) {
                    $this->events[] = ['event' => 'ssh:caddy-source'];

                    return new CommandResult(0, '', '', 1, false);
                }

                $this->events[] = ['event' => $command->protectedInput !== null ? 'ssh:certificate' : 'ssh:other'];

                return new CommandResult(0, '', '', 1, false);
            }
        },
        keys: new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/tmp/orbit-test-key';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 AAAA test';
            }
        },
        knownHosts: new class implements KnownHostsStore
        {
            public function path(): string
            {
                return '/tmp/orbit-test-known-hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
        takeover: app(AiRouterRouteTakeover::class),
    );
}

it('cuts over recorded running collector state on the new release and preserves model access', function (bool $failFirst, bool $switchOff): void {
    $slug = AiRouterRenameCutover::RetiredSlug;
    $this->collector->delete();
    $this->collector = ai_router_takeover_route($this->node, 'collector.cli-proxy-api.orbit', 'http://127.0.0.1:8787');
    // Recorded fleet identities, with synthetic credentials: never read the live database or cache.
    foreach (DB::table('settings')->where('scope_type', 'gateway')->get() as $setting) {
        if (str_starts_with($setting->key, 'ai_router.')) {
            $key = $slug.'.'.substr($setting->key, strlen('ai_router.'));
            $key = str_replace(['.router_url', '.management_key'], ['.cliproxy_url', '.cliproxy_management_key'], $key);
            DB::table('settings')->where('id', $setting->id)->update(['key' => $key]);
        }
    }
    app(ExtensionStore::class)->set('ai-router', false);
    app(SettingRepository::class)->put(new SettingScope(SettingScopeType::Gateway), 'extension.'.$slug.'.enabled', '1');
    DatabaseConnection::query()->create(['name' => 'Valkey', 'slug' => 'cache', 'driver' => 'redis', 'host' => '192.0.2.50', 'port' => 6379]);
    $process = Process::query()->forceCreate([
        'id' => 163, 'owner_type' => Node::class, 'owner_id' => $this->node->id, 'name' => 'cli-proxy-api-collector',
        'runtime' => 'systemd', 'working_directory' => '/var/lib/orbit/'.$slug,
        'runtime_config' => ['command' => ['/usr/bin/python3', '/var/lib/orbit/'.$slug.'/server.py'], 'environment' => [strtoupper($slug).'_PORT' => '8787']],
        'desired_state' => 'running', 'status' => 'active',
    ]);
    DB::statement('ALTER TABLE task_vms RENAME COLUMN ai_router_origin TO model_proxy_origin');
    $vm = DB::table('task_vms')->insertGetId(['provider' => 'incus', 'name' => 'held', 'state' => 'ready', 'wireguard_ip' => '10.44.0.130', 'model_key' => 'held-key-bytes', 'model_proxy_origin' => 'http://192.0.2.17:8317']);
    $cache = app(AiRouterCache::class);
    $cache->put('orbit:'.$slug.':snapshot', 'old snapshot');
    $cache->put('orbit:'.$slug.':lock', 'old holder');
    $cache->put('unrelated', 'keep');
    $cache->put('orbit:ai-router:snapshot', 'new snapshot');
    $events = [];
    app()->instance(AiRouterPublicationManager::class, ai_router_takeover_manager($events, failBuild: $failFirst));
    app()->bind(AiRouterRuntimeLifecycle::class, NativeAiRouterRuntimeLifecycle::class);
    $runtime = new ProcessesApiFakeRuntimeManager;
    app()->instance(ProcessRuntimeManager::class, $runtime);
    $ssh = Mockery::mock(SshExecutor::class);
    $ssh->shouldReceive('execute')->andReturn(new CommandResult(0, AiRouterSourcePublisher::Published, '', 0, false));
    app()->instance(SshExecutor::class, $ssh);
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->andReturn('/tmp/test-key');
    app()->instance(SshKeyProvider::class, $keys);
    $hosts = Mockery::mock(KnownHostsStore::class);
    $hosts->shouldReceive('path')->andReturn('/tmp/test-known-hosts');
    app()->instance(KnownHostsStore::class, $hosts);
    (require base_path('extensions/ai-router/database/migrations/2026_11_16_000001_rename_ai_router_state.php'))->up();
    if ($switchOff) {
        app(ExtensionStore::class)->set('ai-router', false);
    }
    if ($failFirst) {
        expect(fn () => app(ConvergeAiRouterAction::class)->execute())->toThrow(ResourceOperationException::class);
        expect(DB::table('ai_router_cutovers')->count())->toBe(1);
        app()->instance(AiRouterPublicationManager::class, ai_router_takeover_manager($events));
    }
    $this->artisan('orbit:ai-router-converge')->assertSuccessful();
    $fresh = $process->refresh();
    $caddyfile = app(NodeCaddyfileRenderer::class)->render($this->node)->content;
    expect($fresh->id)->toBe(163)
        ->and($fresh->name)->toBe('ai-router-collector')
        ->and($fresh->desired_state->value)->toBe('running')
        ->and($fresh->runtime_config['command'])->toBe(['/usr/bin/python3', '/var/lib/orbit/ai-router/collector.py'])
        ->and($fresh->runtime_config['environment'])->toHaveKeys(['AI_ROUTER_READ_TOKEN', 'AI_ROUTER_MANAGEMENT_KEY', 'AI_ROUTER_PORT'])
        ->and($runtime->removed)->toContain(163)
        ->and($cache->get('orbit:'.$slug.':snapshot'))->toBeNull()
        ->and($cache->get('orbit:'.$slug.':lock'))->toBeNull()
        ->and($cache->get('unrelated'))->toBe('keep')
        ->and($cache->get('orbit:ai-router:snapshot'))->toBe('new snapshot')
        ->and($caddyfile)->toContain('collector.ai-router.orbit')
        ->not->toContain('collector.cli-proxy-api.orbit')
        ->and(Route::query()->find($this->collector->id))->toBeNull()
        ->and(Route::query()->find($this->management->id))->not->toBeNull()
        ->and(DB::table('task_vms')->where('id', $vm)->value('model_key'))->toBe('held-key-bytes')
        ->and(DB::table('task_vms')->where('id', $vm)->value('ai_router_origin'))->toBe('http://192.0.2.17:8317')
        ->and(DB::table('ai_router_cutovers')->count())->toBe(0);
    $removed = $runtime->removed;
    $this->artisan('orbit:ai-router-converge')->assertSuccessful();
    expect($runtime->removed)->toBe($removed);
})->with([[false, false], [true, false], [false, true]]);
