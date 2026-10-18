<?php

declare(strict_types=1);

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Routes\RouteKind;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilder;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Infrastructure\Gateway\GatewayQueueRedis;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Processes\RemoteProcessRuntimeManager;
use App\Infrastructure\Ssh\GatewaySshKeys;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\NativeSshExecutor;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\DatabaseConnection;
use App\Models\Node;
use App\Models\Process;
use App\Models\Route;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\AiRouter\Domain\AiRouterCache;
use Orbit\Extensions\AiRouter\Domain\AiRouterPublicationManager;
use Orbit\Extensions\AiRouter\Domain\AiRouterRuntimeLifecycle;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterRenameCutover;
use Orbit\Extensions\AiRouter\Infrastructure\NativeAiRouterPublicationManager;
use Orbit\Extensions\AiRouter\Infrastructure\NativeAiRouterRuntimeLifecycle;
use Orbit\Extensions\AiRouter\Infrastructure\ValkeyAiRouterCache;
use Symfony\Component\Process\Process as ChildProcess;
use Tests\TestCase;

uses(TestCase::class);

// Explicitly invoked outside the normal suites. This exercises root-owned paths, systemd, SSH, Caddy,
// DNS and Valkey. Only run in an owned disposable Incus VM, never on a fleet Node or developer host.
it('rehearses the automatic rename with native services and continuous held-key requests', function (): void {
    expect(gethostname())->toStartWith('orbit-ai-router-')
        ->and(file_get_contents('/etc/orbit-rehearsal'))->toBe("ai-router-cutover\n");
    $runner = new NativeProcessRunner;
    $run = function (array $arguments, ?string $input = null) use ($runner): string {
        $result = $runner->run(new ProcessInvocation(arguments: $arguments, input: $input, timeout: 120));
        if (! $result->succeeded()) {
            throw new RuntimeException($result->stderr);
        }

        return $result->stdout;
    };
    // Replace test guards only after proving this is the task's disposable machine.
    app()->instance(NativeProcessRunner::class, $runner);
    app()->instance(ProcessRunner::class, $runner);
    app()->bind(SshExecutor::class, NativeSshExecutor::class);
    app()->bind(ProcessRuntimeManager::class, RemoteProcessRuntimeManager::class);
    app()->bind(NodeCaddyBuilds::class, NodeCaddyBuilder::class);
    app()->bind(AiRouterRuntimeLifecycle::class, NativeAiRouterRuntimeLifecycle::class);
    app()->bind(AiRouterPublicationManager::class, NativeAiRouterPublicationManager::class);
    app()->instance(DnsmasqPrivateDnsManager::class, new DnsmasqPrivateDnsManager($runner, app(DevelopmentDnsConfigRenderer::class)));
    app()->instance(PrivateDnsManager::class, app(DnsmasqPrivateDnsManager::class));
    $home = config('orbit.home');
    $database = $home.'/orbit-gateway-test-cutover.sqlite';
    touch($database);
    config(['database.connections.sqlite.database' => $database]);
    DB::purge();
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    $ip = trim($run(['hostname', '-I']));
    $ip = explode(' ', $ip)[0];
    expect($ip)->toStartWith('10.252.');
    $run(['mkdir', '-p', $home.'/ca', $home.'/ssh', '/home/orbit/.ssh']);
    $run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', $home.'/ssh/id_ed25519']);
    file_put_contents('/home/orbit/.ssh/authorized_keys', file_get_contents($home.'/ssh/id_ed25519.pub'), FILE_APPEND);
    chmod('/home/orbit/.ssh/authorized_keys', 0600);
    app()->instance(SshKeyProvider::class, new GatewaySshKeys($home.'/ssh/id_ed25519'));
    $run(['mkdir', '-p', dirname(app(KnownHostsStore::class)->path())]);
    file_put_contents(app(KnownHostsStore::class)->path(), $run(['ssh-keyscan', '-t', 'ed25519', $ip]));
    $run(['openssl', 'req', '-x509', '-newkey', 'rsa:4096', '-nodes', '-days', '2', '-subj', '/CN=Disposable rehearsal CA', '-keyout', $home.'/ca/root.key', '-out', $home.'/ca/root.pem']);
    $run(['sudo', 'install', '-d', '-o', 'orbit', '-g', 'orbit', '/run/orbit']);
    // The collector admits VPN clients. Give this isolated VM a client source address from that subnet.
    $run(['sudo', 'ip', 'address', 'replace', '10.44.0.254/32', 'dev', 'lo']);
    $run(['valkey-cli', '-n', '1', 'FLUSHDB']);
    $run(['sudo', 'bash', '-seu'], "install -d /etc/dnsmasq.d\nprintf 'port=5354\nlisten-address=127.0.0.1\nbind-interfaces\nconf-dir=/etc/dnsmasq.d\n' > /etc/dnsmasq.conf\nsystemctl restart dnsmasq\n");
    $upstream = new ChildProcess(['/usr/bin/python3', base_path('tests/Fixtures/ai-router/rehearsal.py'), $home.'/probe.json']);
    $upstream->start();
    $wait = function (Closure $ready): void {
        $deadline = microtime(true) + 30;
        do {
            if ($ready()) {
                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Native rehearsal did not reach its observable readiness condition.');
    };
    try {
        $wait(fn (): bool => is_file($home.'/probe.json'));
        $node = Node::query()->create(['name' => 'rehearsal', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'wireguard_ip' => $ip, 'public_ssh_host' => $ip]);
        $connection = DatabaseConnection::query()->create(['name' => 'Disposable Valkey', 'slug' => 'cache', 'driver' => 'redis', 'host' => '127.0.0.1', 'port' => 6379]);
        $cache = new ValkeyAiRouterCache($connection);
        app()->instance(AiRouterCache::class, $cache);
        $cache->forgetPrefix('orbit:ai-router:');
        $cache->put('unrelated-rehearsal', 'keep');
        app(ExtensionStore::class)->set('ai-router', true);
        app(AiRouterState::class)->enable($node->id, 'cache', 'http://127.0.0.1:8317', 'synthetic-management', 'synthetic-read', 'synthetic-control');
        $slug = AiRouterRenameCutover::RetiredSlug;
        foreach (DB::table('settings')->where('scope_type', 'gateway')->get() as $setting) {
            if (str_starts_with($setting->key, 'ai_router.')) {
                $key = $slug.'.'.substr($setting->key, strlen('ai_router.'));
                $key = str_replace(['.router_url', '.management_key'], ['.cliproxy_url', '.cliproxy_management_key'], $key);
                DB::table('settings')->where('id', $setting->id)->update(['key' => $key]);
            } elseif ($setting->key === 'extension.ai-router.enabled') {
                DB::table('settings')->where('id', $setting->id)->update(['key' => 'extension.'.$slug.'.enabled']);
            }
        }
        $routes = [];
        foreach (['cli-proxy-api.orbit' => 8317, AiRouterRenameCutover::RetiredHostname => 8787] as $domain => $port) {
            $route = Route::query()->create(['kind' => RouteKind::CustomProxy, 'node_id' => $node->id, 'domain' => $domain, 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
            $route->customProxy()->create(['node_id' => $node->id, 'upstream' => 'http://127.0.0.1:'.$port]);
            app(RemoteAppDevCertificateManager::class)->convergeCustomProxy($route, $node);
            $route->update(['status' => 'active']);
            $routes[$domain] = $route;
        }
        // The moved collector has the same implementation. Derive its retired spelling for the old release.
        $script = str_replace(['AI_ROUTER_', 'orbit:ai-router:'], [strtoupper($slug).'_', 'orbit:'.$slug.':'], file_get_contents(base_path('extensions/ai-router/resources/collector.py')));
        $run(['sudo', 'install', '-d', '-m', '0755', '/var/lib/orbit/'.$slug]);
        $run(['sudo', 'tee', '/var/lib/orbit/'.$slug.'/server.py'], $script);
        $environment = [];
        foreach (['CLIPROXY_URL' => 'http://127.0.0.1:8317', 'MANAGEMENT_KEY' => 'synthetic-management', 'READ_TOKEN' => 'synthetic-read', 'CONTROL_TOKEN' => 'synthetic-control', 'CACHE_HOST' => '127.0.0.1', 'CACHE_PORT' => '6379', 'PORT' => '8787'] as $key => $value) {
            $environment[strtoupper($slug).'_'.$key] = $value;
        }
        $collector = Process::query()->forceCreate(['id' => 163, 'owner_type' => Node::class, 'owner_id' => $node->id, 'name' => 'cli-proxy-api-collector', 'runtime' => 'systemd', 'working_directory' => '/var/lib/orbit/'.$slug, 'runtime_config' => ['command' => ['/usr/bin/python3', '/var/lib/orbit/'.$slug.'/server.py'], 'environment' => $environment], 'desired_state' => 'running', 'status' => 'active']);
        app(ProcessRuntimeManager::class)->converge($collector);
        app(NodeCaddyBuilds::class)->build($node);
        app(PrivateDnsManager::class)->converge();
        $wait(fn (): bool => $cache->get('orbit:'.$slug.':snapshot') !== null);
        $cache->put('orbit:'.$slug.':lock', 'retired-holder', 120);
        DB::statement('ALTER TABLE task_vms RENAME COLUMN ai_router_origin TO model_proxy_origin');
        $vm = DB::table('task_vms')->insertGetId(['provider' => 'incus', 'name' => 'held', 'state' => 'ready', 'wireguard_ip' => '10.44.0.130', 'model_key' => 'held-task-key', 'model_proxy_origin' => 'http://127.0.0.1:8317']);
        $probeBefore = json_decode(file_get_contents($home.'/probe.json'), true, flags: JSON_THROW_ON_ERROR);
        $oldUnit = 'orbit-process-163-cli-proxy-api-collector.service';
        expect(trim($run(['systemctl', 'is-active', $oldUnit])))->toBe('active');
        $started = microtime(true);
        (require base_path('extensions/ai-router/database/migrations/2026_11_16_000001_rename_ai_router_state.php'))->up();
        // Drive the actual registered release tick through the Gateway queue and native command subprocess.
        config(['orbit.gateway_queue.connection' => 'cache', 'queue.connections.gateway' => ['driver' => 'redis', 'connection' => 'gateway', 'queue' => 'tasks', 'retry_after' => 1800]]);
        GatewayQueueRedis::configure();
        $schedule = app(Schedule::class);
        $event = collect($schedule->events())->sole(fn ($event): bool => $event->description === 'orbit:ai-router-converge');
        expect($event->isDue(app()))->toBeTrue()->and($event->filtersPass(app()))->toBeTrue();
        $event->run(app());
        $worker = new ChildProcess([PHP_BINARY, base_path('artisan'), 'queue:work', 'gateway', '--queue=tasks', '--once', '--timeout=120'], base_path(), [
            'APP_ENV' => 'local', 'CACHE_STORE' => 'file', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database,
            'ORBIT_HOME' => $home, 'ORBIT_GATEWAY_QUEUE_CONNECTION' => 'cache', 'ORBIT_GATEWAY_QUEUE_DRIVER' => 'redis',
        ], timeout: 150);
        $worker->run();
        if (! $worker->isSuccessful()) {
            throw new RuntimeException($worker->getErrorOutput().$worker->getOutput());
        }
        $wait(fn (): bool => $cache->get('orbit:ai-router:snapshot') !== null);
        $snapshot = json_decode($cache->get('orbit:ai-router:snapshot'), true, flags: JSON_THROW_ON_ERROR);
        $firstSnapshotSeconds = microtime(true) - $started;
        $this->artisan('orbit:ai-router-converge')->assertSuccessful();
        $probeAfter = json_decode(file_get_contents($home.'/probe.json'), true, flags: JSON_THROW_ON_ERROR);
        $caddyfile = $run(['sudo', 'cat', '/etc/caddy/Caddyfile']);
        $curl = $run(['curl', '--interface', '10.44.0.254', '--fail', '--silent', '--show-error', '--noproxy', '*', '--cacert', $home.'/ca/root.pem', '--resolve', 'collector.ai-router.orbit:443:'.$ip, '-H', 'Authorization: Bearer synthetic-read', 'https://collector.ai-router.orbit/api/v1/usage']);
        expect(json_decode($curl, true, flags: JSON_THROW_ON_ERROR)['providers'])->not->toBeEmpty()
            ->and($snapshot['accounts'][0]['windows'])->not->toBeEmpty()
            ->and($collector->refresh()->name)->toBe('ai-router-collector')
            ->and($collector->runtime_config['command'][1])->toBe('/var/lib/orbit/ai-router/collector.py')
            ->and($collector->runtime_config['environment'])->toHaveKey('AI_ROUTER_READ_TOKEN')
            ->and(file_exists('/etc/systemd/system/'.$oldUnit))->toBeFalse()
            ->and(file_exists('/var/lib/orbit/'.$slug))->toBeFalse()
            ->and(trim($run(['systemctl', 'is-active', 'orbit-process-163-ai-router-collector.service'])))->toBe('active')
            ->and($caddyfile)->toContain('collector.ai-router.orbit')->not->toContain(AiRouterRenameCutover::RetiredHostname)
            ->and(Route::query()->find($routes[AiRouterRenameCutover::RetiredHostname]->id))->toBeNull()
            ->and(Route::query()->find($routes['cli-proxy-api.orbit']->id))->not->toBeNull()
            ->and(trim($run(['valkey-cli', '--scan', '--pattern', 'orbit:'.$slug.':*'])))->toBe('')
            ->and($cache->get('unrelated-rehearsal'))->toBe('keep')
            ->and(DB::table('task_vms')->where('id', $vm)->value('model_key'))->toBe('held-task-key')
            ->and(DB::table('task_vms')->where('id', $vm)->value('ai_router_origin'))->toBe('http://127.0.0.1:8317')
            ->and(DB::table('ai_router_cutovers')->count())->toBe(0)
            ->and($probeAfter['failed'])->toBe(0)
            ->and($probeAfter['ok'])->toBeGreaterThan($probeBefore['ok'])
            ->and($run(['dig', '@127.0.0.1', '-p', '5354', '+short', 'collector.ai-router.orbit']))->toContain($ip)
            ->and(trim($run(['dig', '@127.0.0.1', '-p', '5354', '+short', AiRouterRenameCutover::RetiredHostname])))->toBe('');
        fwrite(STDOUT, '\nNative cutover evidence: '.json_encode(['migration_to_snapshot_seconds' => round($firstSnapshotSeconds, 3), 'model_requests_during_cutover' => $probeAfter['ok'] - $probeBefore['ok'], 'failed_model_requests' => $probeAfter['failed']])."\n");
    } finally {
        $upstream->stop();
        $runner->run(new ProcessInvocation(arguments: ['sudo', 'systemctl', 'stop', 'orbit-process-163-cli-proxy-api-collector.service', 'orbit-process-163-ai-router-collector.service']));
    }
});
