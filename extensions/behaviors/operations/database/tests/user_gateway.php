<?php

declare(strict_types=1);

ini_set('zend.exception_ignore_args', '1');
$root = getenv('DATABASE_USER_GATEWAY_ROOT');
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$scratch = getenv('DATABASE_USER_SCRATCH');
$app->useStoragePath($scratch.'/storage');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync',
    'responsecache.enabled' => false, 'logging.default' => 'single', 'logging.channels.single.path' => $scratch.'/gateway.log',
    'swamp.address' => '127.0.0.1', 'swamp.ca_path' => $scratch.'/cert.pem', 'swamp.run_token' => 'scratch-only-auth-none',
    'operation_executor.worker_node_ids' => [9], 'dependency_detection.services_node_id' => 1]);
$app->instance(App\Infrastructure\AgentCommands\AgentDelivery::class,
    new App\Infrastructure\AgentCommands\AgentDelivery($scratch.'/manifest.json', $scratch.'/artifacts'));

/** Test-only endpoint substitution; real TLS framing and shared start remain unchanged. */
final class ScratchDatabaseSocket implements App\Infrastructure\AgentView\WebSocketClient
{
    private App\Infrastructure\AgentView\StreamWebSocketClient $socket;
    private bool $probed = false;

    public function __construct()
    {
        $this->socket = new App\Infrastructure\AgentView\StreamWebSocketClient;
    }

    public function connect(App\Infrastructure\AgentView\WebSocketEndpoint $endpoint, float $timeoutSeconds): void
    {
        if (! $this->probed) {
            $this->probed = true;
            $operation = App\Models\Operation::query()->where('kind', 'database_user.create')->sole();
            $url = 'http://127.0.0.1:'.getenv('DATABASE_USER_GATEWAY_PORT').'/api/v1/direct-operations/'.$operation->id.'?run_id=20e8138a-cf7f-4c6c-85fd-ff561b575ac8';
            $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 3]]);
            $body = json_decode(file_get_contents($url, false, $context), true, flags: JSON_THROW_ON_ERROR);
            $headers = http_get_last_response_headers();
            if (! str_contains($headers[0], '409') || ($body['error']['code'] ?? null) !== 'operation.run_unbound'
                || isset($operation->fresh()->state['swamp_run_id'])) {
                throw new RuntimeException('Unbound ordering proof failed');
            }
            file_put_contents(getenv('DATABASE_USER_SCRATCH').'/ordering.json', json_encode(['before_start' => 409, 'error_code' => 'operation.run_unbound', 'still_unbound' => true]));
        }
        $this->socket->connect(new App\Infrastructure\AgentView\WebSocketEndpoint('127.0.0.1', (int) getenv('DATABASE_USER_SWAMP_PORT'), 'swamp.orbit', '/', $endpoint->caPath, $endpoint->bearerToken), $timeoutSeconds);
    }

    public function send(array $message): void { $this->socket->send($message); }
    public function receive(float $timeoutSeconds): array { return $this->socket->receive($timeoutSeconds); }
    public function close(): void { $this->socket->close(); }
    public function isConnected(): bool { return $this->socket->isConnected(); }
}

$app->instance(App\Infrastructure\AgentView\WebSocketClient::class, new ScratchDatabaseSocket);

if (PHP_SAPI === 'cli-server') {
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $request = Illuminate\Http\Request::capture();
    $bound = false;
    if (preg_match('#/direct-operations/([0-9]+)#', $request->path(), $match) === 1) {
        $bound = isset(App\Models\Operation::query()->find($match[1])?->state['swamp_run_id']);
    }
    $response = $kernel->handle($request);
    file_put_contents($scratch.'/http.jsonl', json_encode(['path' => $request->path(), 'status' => $response->getStatusCode(), 'bound_before_request' => $bound])."\n", FILE_APPEND);
    $response->send();
    $kernel->terminate($request, $response);
    return;
}

try {
    $mode = $argv[1];
    if ($mode === 'migrate') {
        Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
        echo json_encode(['migrated' => true])."\n";
    } elseif ($mode === 'init') {
        $gateway = App\Models\Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'nckrtl', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '127.0.0.2']);
        App\Models\NodeRole::query()->create(['node_id' => $gateway->id, 'role' => 'gateway', 'status' => 'active']);
        $node = new App\Models\Node(['name' => 'beast', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'nckrtl', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '127.0.0.1']);
        $node->forceFill(['id' => 9])->save();
        $node->accessibleNodes()->attach($gateway);
        $process = new App\Models\Process(['owner_type' => App\Models\Node::class, 'owner_id' => 9, 'name' => getenv('DATABASE_USER_PROCESS_NAME'), 'runtime' => 'docker', 'runtime_config' => [], 'restart_policy' => 'unless-stopped', 'desired_state' => 'running', 'status' => 'active']);
        $process->forceFill(['id' => 2000000023])->save();
        $server = App\Models\DatabaseServer::query()->create(['slug' => 'scratch-proof', 'driver' => 'pgsql', 'node_id' => 9, 'process_id' => $process->id, 'tag' => '18', 'port' => 5432, 'root_password' => '', 'status' => 'active']);
        App\Models\DatabaseConnection::query()->create(['slug' => 'scratch-proof', 'driver' => 'pgsql', 'node_id' => 9, 'database_server_id' => $server->id, 'host' => '127.0.0.1', 'database' => 'postgres', 'username' => 'postgres', 'password' => '']);
        echo json_encode(['initialized' => true])."\n";
    } elseif ($mode === 'admit') {
        $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
        $operation = app(App\Actions\DatabaseConnections\CreateDatabaseUserAction::class)->execute(App\Models\DatabaseConnection::query()->sole(),
            new App\Data\DatabaseConnections\CreateDatabaseUserData('lane10_reader', $input['password'], true), 'scratch-operator');
        $operation->refresh();
        echo json_encode(['id' => $operation->id, 'run_id' => $operation->state['swamp_run_id'] ?? null, 'status' => $operation->status->value, 'writer' => $operation->state['database_user_writer'] ?? null])."\n";
    } elseif ($mode === 'render-foreign') {
        $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
        echo json_encode(['sql' => app(App\Domain\DatabaseServers\PostgresStatements::class)->ensureUser('lane10_reader', $input['password'], knownUser: false)])."\n";
    } elseif ($mode === 'inspect') {
        $operation = App\Models\Operation::query()->where('kind', 'database_user.create')->sole();
        echo json_encode(['operation' => $operation->only(['id', 'status', 'result', 'error_code', 'attempts', 'worker', 'lock_key']), 'run_id' => $operation->state['swamp_run_id'] ?? null, 'users' => App\Models\DatabaseUser::query()->count()])."\n";
    } else {
        throw new RuntimeException('Invalid scratch mode');
    }
} catch (Throwable $error) {
    echo json_encode(['refused' => true, 'class' => get_class($error), 'code' => $error instanceof App\Domain\Shared\ResourceOperationException ? $error->errorCode : 'scratch.failed'])."\n";
    exit(1);
}
