<?php

declare(strict_types=1);
use App\Actions\Tasks\StartDependencyBatchMergeAction;
use App\Domain\GitHub\GitHubApi;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Domain\Tasks\TaskExtensionState;
use App\Infrastructure\AgentView\StreamWebSocketClient;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\ProductionSmokeRoute;

// Isolated real-Gateway fixture. Private values originate and remain in PHP memory.
ini_set('zend.exception_ignore_args', '1');
ini_set('display_errors', '0');
ini_set('log_errors', '0');
umask(0077);
$phase = 'bootstrap';
register_shutdown_function(static function () use (&$phase): void {
    if (! empty($GLOBALS['merge_fixture_finished'])) {
        return;
    }
    $error = error_get_last();
    $category = 'early_exit';
    foreach (['Cannot redeclare', 'must be compatible', 'not found', 'undefined', 'Allowed memory'] as $candidate) {
        if (str_contains($error['message'] ?? '', $candidate)) {
            $category = $candidate;
            break;
        }
    }
    echo json_encode(['refused' => true, 'phase' => $phase, 'category' => $category,
        'file' => basename($error['file'] ?? ''), 'line' => $error['line'] ?? null])."\n";
});
try {
    $root = getenv('DEPENDENCY_MERGE_TEST_GATEWAY_ROOT');
    $scratch = getenv('DEPENDENCY_MERGE_TEST_SCRATCH');
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->useStoragePath($scratch.'/storage');
    $app->make(Kernel::class)->bootstrap();
    if (config('database.default') !== 'sqlite'
        || realpath(config('database.connections.sqlite.database')) !== realpath($scratch.'/gateway.sqlite')) {
        throw new RuntimeException('fixture_database_not_isolated');
    }
    config(['cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync',
        'responsecache.enabled' => false, 'logging.default' => 'single',
        'logging.channels.single.path' => $scratch.'/gateway.log',
        'swamp.address' => '127.0.0.1', 'swamp.ca_path' => $scratch.'/cert.pem',
        'swamp.run_token' => 'isolated-auth-none', 'operation_executor.worker_node_ids' => [209]]);

    /** Substitutes only the dev endpoint; TLS framing and StartSwampRun are real. */
    final class MergeFixtureSocket implements WebSocketClient
    {
        private StreamWebSocketClient $socket;

        public function __construct()
        {
            $this->socket = new StreamWebSocketClient;
        }

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->socket->connect(new WebSocketEndpoint(
                '127.0.0.1', (int) getenv('DEPENDENCY_MERGE_TEST_SWAMP_PORT'), 'swamp.orbit', '/',
                $endpoint->caPath, $endpoint->bearerToken), $timeoutSeconds);
        }

        public function send(array $message): void
        {
            $this->socket->send($message);
        }

        public function receive(float $timeoutSeconds): array
        {
            return $this->socket->receive($timeoutSeconds);
        }

        public function close(): void
        {
            $this->socket->close();
        }

        public function isConnected(): bool
        {
            return $this->socket->isConnected();
        }
    }
    $app->instance(WebSocketClient::class, new MergeFixtureSocket);

    function insist(bool $value, string $code): void
    {
        if (! $value) {
            throw new RuntimeException($code);
        }
    }
    function fixtureScope(): SettingScope
    {
        return new SettingScope(SettingScopeType::Gateway);
    }
    function privateTokens(): array
    {
        return json_decode(app(SettingRepository::class)->get(fixtureScope(), 'dependency.fixture'), true, flags: JSON_THROW_ON_ERROR);
    }
    function publicState(): array
    {
        return json_decode(file_get_contents(getenv('DEPENDENCY_MERGE_TEST_SCRATCH').'/github.json'), true, flags: JSON_THROW_ON_ERROR);
    }
    function scanBytes(string $bytes): void
    {
        foreach (privateTokens() as $token) {
            foreach (array_unique([$token, base64_encode($token), urlencode($token)]) as $needle) {
                insist(! str_contains($bytes, $needle), 'fixture_private_bytes_found');
            }
        }
    }
    // Only this disposable SQLite Project enables the kind.
    config(['dependency_detection.services_node_id' => 209,
        'dependency_merge.projects.20' => ['enabled' => true, 'instance_id' => 1,
            'smoke_workflow' => '.github/workflows/production-smoke.yml']]);

    if (PHP_SAPI === 'cli-server') {
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        if (str_starts_with($path, '/fixture/github')) {
            $endpoint = substr($_SERVER['REQUEST_URI'], strlen('/fixture/github'));
            $phase = 'github';
            $tokens = privateTokens();
            $scope = $_SERVER['REQUEST_METHOD'] === 'PUT' ? 'write_token'
                : (str_contains($endpoint, '/check-runs') ? 'checks_token'
                : (str_contains($endpoint, '/actions/') ? 'actions_token' : (str_contains($endpoint, '/contents/') ? 'read_token' : 'write_token')));
            $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            $valid = $endpoint === '/p2/acme/library.json' ? $authorization === '' : ($_SERVER['REQUEST_METHOD'] === 'DELETE'
                ? in_array(substr($authorization, 7), $tokens, true)
                : hash_equals('Bearer '.$tokens[$scope], $authorization));
            if (! $valid) {
                file_put_contents($scratch.'/token-scope.json', json_encode(['expected' => $scope, 'matched' => array_keys(array_filter($tokens, static fn ($token) => hash_equals('Bearer '.$token, $authorization))), 'present' => $authorization !== '']));
            }
            insist($valid, 'fixture_token_scope_invalid');
            $state = publicState();
            $status = 200;
            if ($_SERVER['REQUEST_METHOD'] === 'DELETE' && $endpoint === '/installation/token') {
                $state['revocations']++;
                $status = 204;
                $body = null;
            } elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
                $body = json_decode(file_get_contents('php://input'), true, flags: JSON_THROW_ON_ERROR);
                insist($endpoint === '/repos/acme/widgets/pulls/3/merge'
                    && $body === ['sha' => str_repeat('a', 40), 'merge_method' => 'squash']
                    && ! $state['merged'] && $state['writes'] === 0, 'fixture_second_or_wrong_squash');
                $state['writes']++;
                $state['merged'] = true;
                // The effect completed; its reply is deliberately uncertain.
                $status = 503;
                $body = ['error' => 'disposable_lost_merge_response'];
            } else {
                insist($_SERVER['REQUEST_METHOD'] === 'GET', 'fixture_method_invalid');
                $evidence = json_decode(file_get_contents($scratch.'/evidence.json'), true, flags: JSON_THROW_ON_ERROR);
                insist(array_key_exists($endpoint, $evidence), 'fixture_unexpected_github_endpoint');
                $body = $evidence[$endpoint];
                if ($endpoint === '/repos/acme/widgets/pulls/3' && $state['merged']) {
                    $body['merged'] = true;
                    $body['state'] = 'closed';
                    $body['merge_commit_sha'] = str_repeat('b', 40);
                    $state['recovery_reads']++;
                }
                $state['reads']++;
                if ($endpoint === '/p2/acme/library.json') {
                    $state['registry_reads']++;
                }
            }
            file_put_contents($scratch.'/github.json', json_encode($state, JSON_THROW_ON_ERROR));
            file_put_contents($scratch.'/github-http.jsonl', json_encode(['method' => $_SERVER['REQUEST_METHOD'],
                'path' => $endpoint, 'status' => $status, 'scope' => $scope, 'scope_valid' => $valid])."\n", FILE_APPEND);
            http_response_code($status);
            header('Content-Type: application/json');
            if ($body !== null) {
                echo json_encode($body, JSON_THROW_ON_ERROR);
            }
        } else {
            $tokens = privateTokens();
            // Only GitHub credential issuance is substituted. The family's secret source is real.
            $github = Mockery::mock(GitHubApi::class);
            $github->shouldReceive('repositoryInstallation')->andReturn(123);
            $github->shouldReceive('repositoryReadToken')->andReturn($tokens['read_token']);
            $github->shouldReceive('repositoryChecksToken')->andReturn($tokens['checks_token']);
            $github->shouldReceive('repositorySquashToken')->andReturn($tokens['write_token']);
            $app->instance(GitHubApi::class, $github);
            Http::preventStrayRequests();
            Http::fake([
                'https://api.github.com/repos/acme/widgets/installation' => Http::response(['id' => 123]),
                'https://api.github.com/app/installations/123/access_tokens' => Http::response([
                    'token' => $tokens['actions_token'], 'permissions' => ['actions' => 'read']]),
            ]);
            $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
            $request = Request::capture();
            $response = $kernel->handle($request);
            scanBytes($request->getContent());
            if (! str_ends_with($request->path(), '/private-material')) {
                scanBytes($response->getContent());
            }
            $operation = preg_match('#direct-operations/([0-9]+)#', $request->path(), $match) ? Operation::find($match[1]) : null;
            file_put_contents($scratch.'/http.jsonl', json_encode(['path' => $request->path(),
                'status' => $response->getStatusCode(), 'run_id' => $request->query('run_id') ?? $request->input('run_id'),
                'bound' => $operation !== null && isset($operation->state['swamp_run_id']),
                'cache_control' => $response->headers->get('Cache-Control')])."\n", FILE_APPEND);
            $response->send();
            $kernel->terminate($request, $response);
        }
        $GLOBALS['merge_fixture_finished'] = true;

        return;
    }
    $phase = $argv[1];
    if ($phase === 'migrate') {
        Artisan::call('migrate', ['--force' => true]);
        $result = ['migrated' => true];
    } elseif ($phase === 'init') {
        $phase = 'init_worker';
        Node::forceCreate(['id' => 209, 'name' => 'dependency-dev-worker', 'status' => 'active',
            'platform' => 'linux', 'architecture' => 'x86_64', 'user' => posix_getpwuid(posix_getuid())['name'],
            'public_ssh_host' => '192.0.2.209', 'wireguard_ip' => '127.0.0.1']);
        NodeRole::create(['node_id' => 209, 'role' => 'gateway', 'status' => 'active']);
        Node::forceCreate(['id' => 10, 'name' => 'disposable-production-record', 'status' => 'active',
            'platform' => 'linux', 'public_ssh_host' => '192.0.2.10', 'wireguard_ip' => '192.0.2.10', 'user' => 'orbit']);
        NodeRole::create(['node_id' => 10, 'role' => 'app-prod', 'status' => 'active']);
        $phase = 'init_project';
        Project::forceCreate(['id' => 20, 'name' => 'Isolated dependency fixture', 'slug' => 'dependency-fixture',
            'repository_url' => 'https://github.com/acme/widgets.git', 'default_branch' => 'main', 'type' => 'laravel-app', 'root' => 'public']);
        $phase = 'init_instance';
        $instance = Instance::forceCreate(['id' => 1, 'project_id' => 20, 'node_id' => 10, 'name' => 'production',
            'source_layout' => 'release', 'checkout_path' => '/nonexistent/fixture/releases/initial',
            'production_user' => 'fixture', 'production_home' => '/nonexistent/fixture', 'root' => 'public',
            'branch' => 'main', 'status' => 'active', 'provisioning_step' => 'active']);
        $phase = 'init_route';
        ProductionSmokeRoute::create($instance);
        $phase = 'init_group';
        Task::forceCreate(['id' => 91, 'project_id' => 20, 'created_by_node_id' => 209, 'title' => 'Dependency batch',
            'brief' => 'Swamp schedule: batch-'.str_repeat('c', 64), 'orchestration' => 'external', 'status' => 'todo',
            'pr_url' => 'https://github.com/acme/widgets/pull/3', 'watched_pr_url' => 'https://github.com/acme/widgets/pull/3',
            'watched_pr_number' => 3]);
        $phase = 'init_child';
        Task::forceCreate(['id' => 92, 'parent_id' => 91, 'title' => 'Update dependencies', 'brief' => 'Update isolated dependencies', 'status' => 'todo',
            'deliverables' => [['id' => 'package', 'type' => 'review', 'description' => 'Dependency evidence']]]);
        $phase = 'init_check';
        $check = TaskCheck::create(['task_id' => 92, 'kind' => 'handoff', 'status' => 'passed', 'pid' => 0,
            'process_started' => 'fixture', 'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('a', 40),
            'commit_sha' => str_repeat('a', 40), 'started_at' => now()]);
        $phase = 'init_publish';
        Operation::forceCreate(['id' => 8, 'kind' => 'tasks.publish', 'target_type' => 'task_group', 'target_id' => 91,
            'status' => 'succeeded', 'sequence' => 1, 'input' => ['check' => $check->id],
            'result' => ['branch' => 'task-91', 'commit' => str_repeat('a', 40), 'pull_request_number' => 3,
                'pull_request_url' => 'https://github.com/acme/widgets/pull/3']]);
        $tokens = [];
        foreach (['write_token', 'read_token', 'checks_token', 'actions_token'] as $name) {
            $tokens[$name] = bin2hex(random_bytes(32));
        }
        app(SettingRepository::class)->put(fixtureScope(), 'dependency.fixture', json_encode($tokens), SettingValueProtection::Secret);
        GitHubTestSupport::storeApp();
        app(TaskExtensionState::class)->enable();
        $result = ['initialized' => true];
    } elseif ($phase === 'admit') {
        $operation = app(StartDependencyBatchMergeAction::class)->execute(Task::topLevel()->findOrFail(91), 8, str_repeat('a', 40), 'dev-canary', 'dev-canary');
        $operation->refresh();
        $result = ['id' => $operation->id, 'run_id' => $operation->state['swamp_run_id'] ?? null];
    } elseif ($phase === 'inspect') {
        $operation = Operation::where('kind', 'tasks.dependency_merge')->sole();
        $result = ['id' => $operation->id, 'run_id' => $operation->state['swamp_run_id'] ?? null,
            'status' => $operation->status->value, 'result' => $operation->result,
            'operation_count' => Operation::where('kind', 'tasks.dependency_merge')->count(), 'github' => publicState(), 'token_scope' => is_file($scratch.'/token-scope.json') ? file_get_contents($scratch.'/token-scope.json') : '', 'fixture_errors' => is_file($scratch.'/github-errors.jsonl') ? file_get_contents($scratch.'/github-errors.jsonl') : ''];
        scanBytes(json_encode($result));
        scanBytes(json_encode($operation->toArray()));
    } elseif ($phase === 'scan') {
        $files = $bytes = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scratch, FilesystemIterator::SKIP_DOTS)) as $file) {
            insist(! $file->isLink() && $file->isFile(), 'fixture_scan_entry_invalid');
            $stream = fopen($file->getPathname(), 'rb');
            $tail = '';
            while (! feof($stream)) {
                $block = fread($stream, 1048576);
                scanBytes($tail.$block);
                $tail = substr($tail.$block, -256);
                $bytes += strlen($block);
            }
            fclose($stream);
            $files++;
        }
        $result = ['whole_scratch_private_free' => true, 'files' => $files, 'bytes' => $bytes,
            'private_needles' => 4, 'production_settings_used' => false];
    } else {
        throw new RuntimeException('fixture_mode_invalid');
    }
    echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
    $GLOBALS['merge_fixture_finished'] = true;
} catch (Throwable $error) {
    if ($phase === 'github') {
        file_put_contents($scratch.'/github-errors.jsonl', json_encode(['code' => $error instanceof RuntimeException && preg_match('/\Afixture_[a-z_0-9]+\z/', $error->getMessage()) ? $error->getMessage() : 'fixture_failed', 'line' => $error->getLine(), 'path' => $endpoint])."\n", FILE_APPEND);
    }
    echo json_encode(['refused' => true, 'phase' => $phase,
        'code' => $error instanceof RuntimeException && preg_match('/\Afixture_[a-z_0-9]+\z/', $error->getMessage()) ? $error->getMessage() : 'fixture_failed',
        'type' => get_class($error), 'sqlstate' => $error->getCode(), 'trace' => array_map(static fn ($row) => basename($row['file'] ?? '').':'.($row['line'] ?? 0).':'.($row['function'] ?? ''), array_slice($error->getTrace(), 0, 8)), 'file' => basename($error->getFile()), 'line' => $error->getLine()])."\n";
    $GLOBALS['merge_fixture_finished'] = true;
    exit(1);
}
