<?php

declare(strict_types=1);

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Fleet\NodeFootprint;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Routes\RouteKind;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilder;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Infrastructure\Gateway\GatewayQueueRedis;
use App\Infrastructure\Nodes\AgentCaddyPackage;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\AiRouter\Domain\AiRouterCache;
use Orbit\Extensions\AiRouter\Domain\AiRouterLifecycleLock;
use Orbit\Extensions\AiRouter\Domain\AiRouterPublicationManager;
use Orbit\Extensions\AiRouter\Domain\AiRouterRuntimeLifecycle;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterFootprintArtifact;
use Orbit\Extensions\AiRouter\Infrastructure\NativeAiRouterPublicationManager;
use Orbit\Extensions\AiRouter\Infrastructure\NativeAiRouterRuntimeLifecycle;
use Orbit\Extensions\AiRouter\Infrastructure\ValkeyAiRouterCache;
use Symfony\Component\Process\Process as ChildProcess;
use Tests\Support\RecordedAiRouterState;
use Tests\TestCase;

uses(TestCase::class);

pest()->group('privileged');

// Explicitly invoked outside the normal suites. This exercises root-owned paths, systemd, SSH, Caddy,
// DNS and Valkey. Only run in an owned disposable Incus VM, never on a fleet Node or developer host.
it('rehearses the automatic rename with native services and continuous held-key requests', function (): void {
    expect(gethostname())->toStartWith('orbit-ai-router-')
        ->and(file_get_contents('/etc/orbit-rehearsal'))->toBe("ai-router-cutover\n");
    config(['cache.default' => 'file']);
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
    $newUnit = null;
    try {
        $wait(fn (): bool => is_file($home.'/probe.json'));
        $node = Node::query()->create(['name' => 'rehearsal', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'wireguard_ip' => $ip, 'public_ssh_host' => $ip]);
        $connection = DatabaseConnection::query()->create(['name' => 'Disposable Valkey', 'slug' => 'cache', 'driver' => 'redis', 'host' => '127.0.0.1', 'port' => 6379]);
        $cache = new ValkeyAiRouterCache($connection);
        app()->instance(AiRouterCache::class, $cache);
        $cache->put('unrelated-rehearsal', 'keep');
        $collector = RecordedAiRouterState::seed($node, $connection, 'http://127.0.0.1:8317');
        $slug = 'proxycli';
        $routes = [];
        foreach (['cli-proxy-api.orbit' => 8317] as $domain => $port) {
            $route = Route::query()->forceCreate(['id' => 104, 'kind' => RouteKind::CustomProxy, 'node_id' => $node->id, 'domain' => $domain, 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
            $route->customProxy()->create(['node_id' => $node->id, 'upstream' => 'http://127.0.0.1:'.$port]);
            app(RemoteAppDevCertificateManager::class)->convergeCustomProxy($route, $node);
            $route->update(['status' => 'active']);
            $routes[$domain] = $route;
        }
        $script = file_get_contents(base_path('tests/Fixtures/ai-router/old-collector.py'));
        $run(['sudo', 'install', '-d', '-m', '0755', '/var/lib/orbit/proxycli']);
        $run(['sudo', 'tee', '/var/lib/orbit/proxycli/server.py'], $script);
        app(ProcessRuntimeManager::class)->converge($collector);
        expect(app(AgentCaddyPackage::class)->install($node)->succeeded())->toBeTrue();
        app(NodeCaddyBuilds::class)->build($node);
        app(PrivateDnsManager::class)->converge();
        // Recorded pre-release site, not a Route: only the management Route existed on the Gateway.
        $leaf = app(GatewayCertificateIssuer::class)->issue('collector.cli-proxy-api.orbit', $ip);
        $run(['sudo', 'install', '-d', '-o', 'root', '-g', 'caddy', '-m', '0750', '/etc/caddy/orbit-proxycli-cert-current']);
        $run(['sudo', 'install', '-o', 'root', '-g', 'caddy', '-m', '0640', $leaf->certificatePath, '/etc/caddy/orbit-proxycli-cert-current/proxycli.pem']);
        $run(['sudo', 'install', '-o', 'root', '-g', 'caddy', '-m', '0640', $leaf->privateKeyPath, '/etc/caddy/orbit-proxycli-cert-current/proxycli.key']);
        $oldSite = "\n# Managed by Orbit: proxycli\ncollector.cli-proxy-api.orbit {\n bind {$ip}\n tls /etc/caddy/orbit-proxycli-cert-current/proxycli.pem /etc/caddy/orbit-proxycli-cert-current/proxycli.key\n reverse_proxy 127.0.0.1:8787 {\n lb_try_duration 5s\n }\n}\n";
        $run(['sudo', 'tee', '-a', '/etc/caddy/Caddyfile'], $oldSite);
        $run(['sudo', 'systemctl', 'reload', 'caddy']);
        $run(['sudo', 'tee', '-a', '/etc/dnsmasq.d/orbit-records.conf'], "\nhost-record=collector.cli-proxy-api.orbit,{$ip}\n");
        $run(['sudo', 'systemctl', 'restart', 'dnsmasq']);

        $wait(fn (): bool => $cache->get('orbit:'.$slug.':snapshot') !== null);
        $cache->put('orbit:'.$slug.':lock', 'retired-holder', 120);
        $vm = DB::table('task_vms')->insertGetId(['provider' => 'incus', 'name' => 'held', 'state' => 'ready', 'wireguard_ip' => '10.44.0.130', 'model_key' => 'held-task-key', 'ai_router_origin' => 'http://127.0.0.1:8317']);
        $probeBefore = json_decode(file_get_contents($home.'/probe.json'), true, flags: JSON_THROW_ON_ERROR);
        $oldUnit = 'orbit-process-163-cli-proxy-api-collector.service';
        expect(trim($run(['systemctl', 'is-active', $oldUnit])))->toBe('active');
        $started = microtime(true);
        $footprint = new NodeFootprint([app(AiRouterFootprintArtifact::class)]);
        $footprint->converge($node);
        expect(trim($run(['systemctl', 'is-active', $oldUnit])))->toBe('active')
            ->and(file_exists('/etc/systemd/system/orbit-process-163-ai-router-collector.service'))->toBeFalse();
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
        $worker->start();
        $wait(function (): bool {
            $lock = Cache::lock(AiRouterLifecycleLock::Name, 600);
            if (! $lock->get()) {
                return true;
            }
            $lock->release();

            return false;
        });
        // A rollout arriving during the tick waits on that same lock, then sees the completed spec.
        $footprint->converge($node, force: true);
        $worker->wait();
        if (! $worker->isSuccessful()) {
            throw new RuntimeException($worker->getErrorOutput().$worker->getOutput());
        }
        $wait(fn (): bool => $cache->get('orbit:ai-router:snapshot') !== null);
        $snapshot = json_decode($cache->get('orbit:ai-router:snapshot'), true, flags: JSON_THROW_ON_ERROR);
        $firstSnapshotSeconds = microtime(true) - $started;
        $replacement = Process::query()->where('name', 'ai-router-collector')->sole();
        $newUnit = 'orbit-process-'.$replacement->id.'-ai-router-collector.service';
        $journal = $run(['sudo', 'journalctl', '--unit='.$newUnit, '--since=@'.(int) $started, '--output=json', '--no-pager']);
        $starts = array_filter(explode("\n", trim($journal)), static fn (string $line): bool => str_starts_with(json_decode($line, true, flags: JSON_THROW_ON_ERROR)['MESSAGE'] ?? '', 'Started '));
        expect($starts)->toHaveCount(1);
        $pid = trim($run(['systemctl', 'show', '--property=MainPID', '--value', $newUnit]));
        $footprint->converge($node, force: true);
        $this->artisan('orbit:ai-router-converge')->assertSuccessful();
        expect(trim($run(['systemctl', 'show', '--property=MainPID', '--value', $newUnit])))->toBe($pid);
        $probeAfter = json_decode(file_get_contents($home.'/probe.json'), true, flags: JSON_THROW_ON_ERROR);
        if ($probeAfter['failed'] > 0) {
            fwrite(STDERR, json_encode($probeAfter, flags: JSON_THROW_ON_ERROR)."\n");
        }
        $caddyfile = $run(['sudo', 'cat', '/etc/caddy/Caddyfile']);
        $curl = $run(['curl', '--interface', '10.44.0.254', '--fail', '--silent', '--show-error', '--noproxy', '*', '--cacert', $home.'/ca/root.pem', '--resolve', 'collector.ai-router.orbit:443:'.$ip, '-H', 'Authorization: Bearer synthetic-read', 'https://collector.ai-router.orbit/api/v1/usage']);
        expect(json_decode($curl, true, flags: JSON_THROW_ON_ERROR)['providers'])->not->toBeEmpty()
            ->and($snapshot['accounts'][0]['windows'])->not->toBeEmpty()
            ->and($replacement->name)->toBe('ai-router-collector')
            ->and($replacement->runtime_config['command'][1])->toBe('/var/lib/orbit/ai-router/collector.py')
            ->and($replacement->runtime_config['environment'])->toHaveKey('AI_ROUTER_READ_TOKEN')
            ->and(file_exists('/etc/systemd/system/'.$oldUnit))->toBeFalse()
            ->and(file_exists('/var/lib/orbit/'.$slug))->toBeFalse()
            ->and(trim($run(['systemctl', 'is-active', $newUnit])))->toBe('active')
            ->and($caddyfile)->toContain('collector.ai-router.orbit')->not->toContain('collector.cli-proxy-api.orbit')
            ->and(Route::query()->find($routes['cli-proxy-api.orbit']->id))->not->toBeNull()
            ->and(trim($run(['valkey-cli', '--scan', '--pattern', 'orbit:'.$slug.':*'])))->toBe('')
            ->and($cache->get('unrelated-rehearsal'))->toBe('keep')
            ->and(DB::table('task_vms')->where('id', $vm)->value('model_key'))->toBe('held-task-key')
            ->and(DB::table('task_vms')->where('id', $vm)->value('ai_router_origin'))->toBe('http://127.0.0.1:8317')
            ->and(DB::table('ai_router_cutovers')->count())->toBe(0)
            ->and($probeAfter['failed'])->toBe(0)
            ->and($probeAfter['ok'])->toBeGreaterThan($probeBefore['ok'])
            ->and($run(['dig', '@127.0.0.1', '-p', '5354', '+short', 'collector.ai-router.orbit']))->toContain($ip)
            ->and(trim($run(['dig', '@127.0.0.1', '-p', '5354', '+short', 'collector.cli-proxy-api.orbit'])))->toBe('');
        fwrite(STDOUT, '\nNative cutover evidence: '.json_encode(['migration_to_snapshot_seconds' => round($firstSnapshotSeconds, 3), 'model_requests_during_cutover' => $probeAfter['ok'] - $probeBefore['ok'], 'failed_model_requests' => $probeAfter['failed'], 'rollout_before_tick' => true, 'rollout_during_tick' => true])."\n");
    } finally {
        $upstream->stop();
        $units = array_filter(['orbit-process-163-cli-proxy-api-collector.service', $newUnit]);
        $runner->run(new ProcessInvocation(arguments: ['sudo', 'systemctl', 'stop', ...$units]));
        $runner->run(new ProcessInvocation(arguments: ['sudo', 'rm', '-f', '--', ...array_map(fn (string $unit): string => '/etc/systemd/system/'.$unit, $units)]));
        $runner->run(new ProcessInvocation(arguments: ['sudo', 'systemctl', 'daemon-reload']));
    }
});
