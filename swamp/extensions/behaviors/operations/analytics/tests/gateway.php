<?php

declare(strict_types=1);

use App\Actions\Analytics\RecordAnalyticsIntentAction;
use App\Domain\Analytics\AnalyticsStatsKeyStore;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Infrastructure\AgentView\StreamWebSocketClient;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

// The fixture creates every private value in PHP memory. Only encrypted settings persist.
ini_set('zend.exception_ignore_args', '1');
ini_set('display_errors', '0');
ini_set('log_errors', '0');
umask(0077);
$phase = 'bootstrap';
register_shutdown_function(static function () use (&$phase): void {
    if (empty($GLOBALS['analytics_fixture_finished'])) {
        $error = error_get_last();
        echo json_encode(['refused' => true, 'phase' => $phase, 'file' => basename($error['file'] ?? ''), 'line' => $error['line'] ?? null])."\n";
    }
});
try {
    $root = getenv('ANALYTICS_TEST_GATEWAY_ROOT');
    $scratch = getenv('ANALYTICS_TEST_SCRATCH');
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->useStoragePath($scratch.'/storage');
    $app->make(Kernel::class)->bootstrap();
    if (config('database.default') !== 'sqlite' || realpath(config('database.connections.sqlite.database')) !== realpath($scratch.'/gateway.sqlite')) {
        throw new RuntimeException('fixture_database_not_isolated');
    }
    config(['cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync', 'responsecache.enabled' => false,
        'logging.default' => 'single', 'logging.channels.single.path' => $scratch.'/gateway.log',
        'swamp.address' => '127.0.0.1', 'swamp.ca_path' => $scratch.'/cert.pem', 'swamp.run_token' => 'isolated-auth-none',
        'operation_executor.worker_node_ids' => [209, 210], 'dependency_detection.services_node_id' => 209,
        'orbit.home' => $scratch.'/orbit-home', 'orbit.agent.delivery' => $scratch.'/agent-delivery.json']);

    final class AnalyticsFixtureSocket implements WebSocketClient
    {
        private StreamWebSocketClient $socket;
        public function __construct() { $this->socket = new StreamWebSocketClient; }
        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void {
            $this->socket->connect(new WebSocketEndpoint('127.0.0.1', (int) getenv('ANALYTICS_TEST_SWAMP_PORT'), 'swamp.orbit', '/', $endpoint->caPath, $endpoint->bearerToken), $timeoutSeconds);
        }
        public function send(array $message): void { $this->socket->send($message); }
        public function receive(float $timeoutSeconds): array { return $this->socket->receive($timeoutSeconds); }
        public function close(): void { $this->socket->close(); }
        public function isConnected(): bool { return $this->socket->isConnected(); }
    }
    $app->instance(WebSocketClient::class, new AnalyticsFixtureSocket);
    $app->instance(ManagedUserAccountResolver::class, new class implements ManagedUserAccountResolver {
        public function resolve(Node $node): ManagedUserAccount {
            return new ManagedUserAccount($node->user, $node->user, getenv('ANALYTICS_TEST_SCRATCH').'/host-home');
        }
    });
    function insist(bool $condition, string $code): void {
        if (! $condition) { throw new RuntimeException($code); }
    }
    function scope(): SettingScope { return new SettingScope(SettingScopeType::Gateway); }
    function privateValues(): array {
        return json_decode(app(SettingRepository::class)->get(scope(), 'analytics.fixture'), true, flags: JSON_THROW_ON_ERROR);
    }
    function scanBytes(string $bytes): void {
        foreach (privateValues() as $value) {
            foreach (array_unique([$value, base64_encode($value), urlencode($value)]) as $needle) {
                insist(! str_contains($bytes, $needle), 'fixture_private_bytes_found');
            }
        }
    }
    function dockerInput(array $arguments, string $input): string {
        $process = proc_open(['docker', ...$arguments], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        insist(is_resource($process), 'fixture_backend_process_failed');
        fwrite($pipes[0], $input); fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) {
            // Never expose provider error bodies or arguments that may contain credentials.
            throw new RuntimeException('fixture_backend_command_failed');
        }
        return $output;
    }
    if (PHP_SAPI === 'cli-server') {
        // The TLS relay chooses one fixed fixture identity per listener. No application auth is bypassed.
        $_SERVER['REMOTE_ADDR'] = ($_SERVER['HTTP_X_ANALYTICS_FIXTURE_PARTICIPANT'] ?? '') === '210' ? '127.0.0.210' : '127.0.0.1';
        $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
        $request = Request::capture();
        $response = $kernel->handle($request);
        scanBytes($request->getContent());
        if (! str_ends_with($request->path(), '/private-material')) { scanBytes($response->getContent()); }
        $operation = preg_match('#direct-operations/([0-9]+)#', $request->path(), $match) ? Operation::find($match[1]) : null;
        file_put_contents($scratch.'/http.jsonl', json_encode(['path' => $request->path(), 'status' => $response->getStatusCode(),
            'caller' => $_SERVER['REMOTE_ADDR'], 'run_id' => $request->query('run_id') ?? $request->input('run_id'),
            'bound' => $operation !== null && isset($operation->state['swamp_run_id']),
            'cache_control' => $response->headers->get('Cache-Control')])."\n", FILE_APPEND);
        $response->send(); $kernel->terminate($request, $response);
        $GLOBALS['analytics_fixture_finished'] = true;
        return;
    }
    $phase = $argv[1];
    if ($phase === 'migrate') {
        Artisan::call('migrate', ['--force' => true]);
        $result = ['migrated' => true];
    } elseif ($phase === 'init') {
        $user = posix_getpwuid(posix_getuid())['name'];
        $phase = 'init_cluster';
        $cluster = Cluster::forceCreate(['id' => 1, 'name' => 'isolated', 'tld' => 'fixture.test', 'state' => 'active']);
        foreach ([209 => ['analytics', null, '127.0.0.1'], 210 => ['router', 1, '127.0.0.210'], 211 => ['app-prod', 1, '127.0.0.211']] as $id => [$role, $clusterId, $ip]) {
            $phase = 'init_node_'.$id;
            $node = Node::forceCreate(['id' => $id, 'name' => 'analytics-fixture-'.$id, 'status' => 'active',
                'platform' => 'linux', 'architecture' => 'x86_64', 'user' => $user,
                'public_ssh_host' => '192.0.2.'.($id - 200), 'wireguard_ip' => $ip,
                'lan_ip' => $clusterId === null ? null : '10.10.0.'.($id - 200), 'cluster_id' => $clusterId]);
            $phase = 'init_role_'.$id;
            NodeRole::forceCreate(['node_id' => $id, 'role' => $role, 'status' => 'active', 'cluster_id' => $role === 'router' ? $clusterId : null]);
        }
        NodeRole::forceCreate(['node_id' => 209, 'role' => 'gateway', 'status' => 'active']);
        NodeRole::forceCreate(['node_id' => 210, 'role' => 'vpn', 'status' => 'active']);
        Node::findOrFail(210)->accessibleNodes()->attach(Node::findOrFail(209));
        $phase = 'init_project';
        Project::forceCreate(['id' => 20, 'name' => 'Analytics disposable fixture', 'slug' => 'analytics-fixture',
            'repository_url' => 'https://example.test/disposable.git', 'default_branch' => 'main', 'root' => 'public']);
        $phase = 'init_instance';
        $instance = Instance::forceCreate(['id' => 1, 'project_id' => 20, 'node_id' => 211, 'name' => 'canary', 'source_layout' => 'release',
            'checkout_path' => $scratch.'/checkout', 'branch' => 'main', 'status' => 'active', 'provisioning_step' => 'active',
            'source_is_laravel' => false, 'production_home' => $scratch.'/host-home', 'production_user' => $user, 'selected_php_version' => '8.5']);
        $phase = 'init_route';
        $route = Route::forceCreate(['kind' => 'app', 'project_id' => 20, 'domain' => 'analytics-canary.example.test',
            'cluster_id' => 1, 'publication' => 'private', 'provenance' => 'explicit', 'status' => 'pending']);
        $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
        $route->update(['status' => 'active']);
        $values = ['email' => 'canary-'.bin2hex(random_bytes(8)).'@example.test', 'password' => bin2hex(random_bytes(32)),
            'apiKey' => bin2hex(random_bytes(32)), 'runtimeKey' => base64_encode(random_bytes(64)), 'totpKey' => base64_encode(random_bytes(32))];
        $settings = app(SettingRepository::class);
        $settings->put(scope(), 'analytics.fixture', json_encode($values), SettingValueProtection::Secret);
        $settings->put(scope(), 'analytics.management_email', $values['email'], SettingValueProtection::Secret);
        $settings->put(scope(), 'analytics.management_password', $values['password'], SettingValueProtection::Secret);
        app(AnalyticsStatsKeyStore::class)->put($values['apiKey']);
        $result = ['initialized' => true];
    } elseif ($phase === 'backend-start') {
        $private = privateValues();
        dockerInput(['run', '-d', '--rm', '--name', getenv('ANALYTICS_TEST_BACKEND'), '--network', getenv('ANALYTICS_TEST_NETWORK'),
            '-p', '127.0.0.1:'.getenv('ANALYTICS_TEST_BACKEND_PORT').':8000', '--entrypoint', 'sh',
            '-e', 'DATABASE_URL=postgres://postgres@postgres:5432/plausible_db',
            '-e', 'CLICKHOUSE_DATABASE_URL=http://clickhouse:8123/plausible_events_db',
            '-e', 'BASE_URL=http://localhost:8000', '-e', 'HTTP_PORT=8000', '-e', 'DISABLE_REGISTRATION=false',
            'ghcr.io/plausible/community-edition:v3.2.1', '-c', 'sleep infinity'], '');
        dockerInput(['exec', '-i', getenv('ANALYTICS_TEST_BACKEND'), 'sh', '-c',
            'read -r SECRET_KEY_BASE; read -r TOTP_VAULT_KEY; export SECRET_KEY_BASE TOTP_VAULT_KEY; (/app/bin/plausible eval "Plausible.Release.createdb()" && /app/bin/plausible eval "Plausible.Release.interweave_migrate()" && exec /app/bin/plausible start) >/tmp/provider.log 2>&1 </dev/null &'],
            $private['runtimeKey']."\n".$private['totpKey']."\n");
        $result = ['backend_started' => true];
    } elseif ($phase === 'backend-init') {
        $private = privateValues();
        $code = 'user = Plausible.Auth.User.new(%{name: "Disposable Canary", email: '.json_encode($private['email']).', password: '.json_encode($private['password']).', password_confirmation: '.json_encode($private['password']).'}) |> Ecto.Changeset.put_change(:email_verified, true) |> Plausible.Repo.insert!(); '
            .' {:ok, team} = Plausible.Teams.get_or_create(user); '
            .' Plausible.Auth.ApiKey.changeset(%Plausible.Auth.ApiKey{}, team, %{name: "Disposable Canary", user_id: user.id, key: '.json_encode($private['apiKey']).'}) |> Plausible.Repo.insert!(); IO.puts("fixture_bootstrapped")';
        $output = dockerInput(['exec', '-i', getenv('ANALYTICS_TEST_BACKEND'), 'sh', '-c', 'read -r SECRET_KEY_BASE; read -r TOTP_VAULT_KEY; export SECRET_KEY_BASE TOTP_VAULT_KEY; exec /app/bin/plausible rpc \'Code.eval_string(IO.read(:stdio, :eof))\''], privateValues()['runtimeKey']."\n".privateValues()['totpKey']."\n".$code);
        insist(str_contains($output, 'fixture_bootstrapped'), 'fixture_backend_bootstrap_unproven');
        $result = ['backend_initialized' => true];
    } elseif ($phase === 'enable' || $phase === 'disable') {
        $operation = app(RecordAnalyticsIntentAction::class)->execute(Instance::findOrFail(1), $phase === 'enable', [], 'UTC');
        $operation->refresh();
        $result = ['id' => $operation->id, 'run_id' => $operation->state['swamp_run_id'] ?? null, 'kind' => $operation->kind];
    } elseif ($phase === 'inspect') {
        $operations = Operation::orderBy('id')->get();
        $result = ['operations' => $operations->map(fn ($op) => ['id' => $op->id, 'run_id' => $op->state['swamp_run_id'] ?? null,
            'status' => $op->status->value, 'kind' => $op->kind, 'result' => $op->result])->all(),
            'tracking_count' => Route::where('kind', 'analytics_tracking')->count(),
            'binding_present' => app(SettingRepository::class)->get(scope(), 'analytics.site.1') !== null];
        scanBytes(json_encode([$result, $operations->toArray()]));
    } elseif ($phase === 'backend-ingest-observe') {
        $output = dockerInput(['exec', getenv('ANALYTICS_TEST_BACKEND'), 'cat', '/tmp/fixture-drop-reason'], '');
        $result = ['drop_reason' => preg_match('/\A[a-z_]+\z/', trim($output)) ? trim($output) : 'unclassified'];
    } elseif ($phase === 'backend-observe') {
        $private = privateValues();
        $code = 'key = Plausible.Repo.one!(Plausible.Auth.ApiKey); site = Plausible.Repo.get_by(Plausible.Site, domain: "analytics-canary.example.test"); IO.puts(Jason.encode!(%{key_hash_matches: key.key_hash == Plausible.Auth.ApiKey.do_hash('.json_encode($private['apiKey']).'), key_team: key.team_id, site_team: site && site.team_id, teams: Plausible.Repo.aggregate(Plausible.Teams.Team, :count)}))';
        $output = dockerInput(['exec', '-i', getenv('ANALYTICS_TEST_BACKEND'), 'sh', '-c', 'read -r SECRET_KEY_BASE; read -r TOTP_VAULT_KEY; export SECRET_KEY_BASE TOTP_VAULT_KEY; exec /app/bin/plausible rpc \'Code.eval_string(IO.read(:stdio, :eof))\''], $private['runtimeKey']."\n".$private['totpKey']."\n".$code);
        $result = json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);
    } elseif ($phase === 'backend-inspect' || $phase === 'backend-manual-goal') {
        $code = $phase === 'backend-manual-goal'
            ? 'site = Plausible.Repo.get_by!(Plausible.Site, domain: "analytics-canary.example.test"); %Plausible.Goal{} |> Plausible.Goal.changeset(%{site_id: site.id, event_name: "Manual retained goal"}) |> Plausible.Repo.insert!(); :telemetry.attach("fixture-drop", Plausible.Ingestion.Event.telemetry_event_dropped(), fn _, _, meta, _ -> File.write!("/tmp/fixture-drop-reason", if(is_atom(meta.reason), do: Atom.to_string(meta.reason), else: "validation")) end, nil); IO.puts("fixture_manual_goal_added")'
            : 'site = Plausible.Repo.get_by!(Plausible.Site, domain: "analytics-canary.example.test"); IO.puts("fixture_site_retained"); if Plausible.Repo.get_by(Plausible.Goal, site_id: site.id, event_name: "Manual retained goal"), do: IO.puts("fixture_goal_retained")';
        $output = dockerInput(['exec', '-i', getenv('ANALYTICS_TEST_BACKEND'), 'sh', '-c', 'read -r SECRET_KEY_BASE; read -r TOTP_VAULT_KEY; export SECRET_KEY_BASE TOTP_VAULT_KEY; exec /app/bin/plausible rpc \'Code.eval_string(IO.read(:stdio, :eof))\''], privateValues()['runtimeKey']."\n".privateValues()['totpKey']."\n".$code);
        $result = ['site_retained' => str_contains($output, 'fixture_site_retained'), 'manual_goal_retained' => str_contains($output, 'fixture_goal_retained'),
            'manual_goal_added' => str_contains($output, 'fixture_manual_goal_added')];
    } elseif ($phase === 'scan') {
        $files = $bytes = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scratch, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isLink()) {
                insist(str_starts_with(realpath($file->getPathname()) ?: '', $scratch.'/'), 'fixture_scan_link_escaped');
                continue;
            }
            insist($file->isFile(), 'fixture_scan_entry_invalid');
            $stream = fopen($file->getPathname(), 'rb'); $tail = '';
            while (! feof($stream)) {
                $block = fread($stream, 1048576); scanBytes($tail.$block); $tail = substr($tail.$block, -512); $bytes += strlen($block);
            }
            fclose($stream); $files++;
        }
        $result = ['whole_scratch_private_free' => true, 'files' => $files, 'bytes' => $bytes, 'private_needles' => count(privateValues()), 'production_settings_used' => false];
    } else { throw new RuntimeException('fixture_mode_invalid'); }
    echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
    $GLOBALS['analytics_fixture_finished'] = true;
} catch (Throwable $error) {
    echo json_encode(['refused' => true, 'phase' => $phase, 'code' => preg_match('/\Afixture_[a-z_0-9]+\z/', $error->getMessage()) ? $error->getMessage() : 'fixture_failed',
        'type' => get_class($error), 'file' => basename($error->getFile()), 'line' => $error->getLine(),
        'trace' => array_map(static fn ($row) => basename($row['file'] ?? '').':'.($row['line'] ?? 0).':'.($row['function'] ?? ''), array_slice($error->getTrace(), 0, 7))])."\n";
    $GLOBALS['analytics_fixture_finished'] = true;
    exit(1);
}
