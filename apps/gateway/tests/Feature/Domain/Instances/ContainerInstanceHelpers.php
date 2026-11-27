<?php

declare(strict_types=1);

use App\Actions\Instances\StartDevelopmentInstanceCreateAction;
use App\Actions\Nodes\AllocateInstanceBridgeAction;
use App\Data\Instances\CreateInstanceData;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\InstancePool;

/* The Nodes, Projects and admitted creates of the container Instance tests, shared by their files. */

function containerDefaultNode(string $name = 'beast', string $ip = '10.44.0.7', bool $listed = true): Node
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => $ip, 'public_ssh_host' => $ip, 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
        'settings' => ['apps' => ['path' => '/home/orbit/apps']],
    ]));
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    if ($listed) {
        InstancePool::give($node);
        app(AllocateInstanceBridgeAction::class)->execute($node);
    }

    return $node->refresh();
}

function containerDefaultProject(ProjectType $type = ProjectType::NodePackage, ?string $php = null): Project
{
    Http::fake(['api.github.com/repos/nckrtl/tool' => Http::response(['full_name' => 'nckrtl/tool', 'private' => false])]);

    return Project::query()->create(['name' => 'tool', 'slug' => 'tool', 'type' => $type, 'repository_url' => 'https://github.com/nckrtl/tool.git',
        'default_branch' => 'main', 'root' => $type === ProjectType::LaravelApp ? 'public' : '.', 'php_version' => $php, 'is_laravel' => false, 'source_facts_captured_at' => now()]);
}

/** The admitted Operation; its native start stays queued. */
function containerCreate(Project $project, Node $node, string $name = 'default', ?string $databaseServer = null): Operation
{
    return app(StartDevelopmentInstanceCreateAction::class)->execute(
        new CreateInstanceData($project->id, $node->id, $name, null, null, null, $databaseServer), null, null);
}

/** @return array<string, mixed>|null */
function containerIntentOf(Operation $operation): ?array
{
    $container = ValidatedData::object($operation->input['direct_provisioning'])['container'];

    return $container === null ? null : ValidatedData::object($container);
}

function containerRefusal(Closure $create): string
{
    try {
        $create();
    } catch (ResourceOperationException $exception) {
        return $exception->errorCode;
    }

    return 'admitted';
}

/**
 * The SSH call of the workspace read (ContainerWorkspaceRead), as the Node's reader answers it. It records each command with the
 * transaction level it ran at, so a test can prove no lock waits on it. A null answer fails the call.
 */
function workspaceReader(?string $answer = null): object
{
    $reader = new class($answer ?? (string) json_encode(['heads' => [str_repeat('a', 40), '--all'], 'stashes' => [], 'untracked' => ['notes.txt', 7]])) implements SshExecutor
    {
        /** @var list<array{arguments: list<string>, level: int, command_timeout: float, timeout: float, budget: ?string}> */
        public array $calls = [];

        /** The first calls that fail as a lost connection does, before the answer. */
        public int $lost = 0;

        public function __construct(public ?string $answer) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->calls[] = ['arguments' => $command->arguments, 'level' => DB::transactionLevel(), 'command_timeout' => (float) $connection->commandTimeout,
                'timeout' => (float) $command->timeout, 'budget' => preg_match('/^BUDGET = (.+)$/m', (string) $command->input, $match) === 1 ? $match[1] : null];
            if (count($this->calls) <= $this->lost) {
                return new CommandResult(255, '', 'ssh: connect to host 10.44.0.7 port 22: Connection timed out', 2000, false);
            }

            return $this->answer === null ? new CommandResult(1, '', '', 5, false) : new CommandResult(0, $this->answer, '', 5, false);
        }
    };
    app()->instance(SshExecutor::class, $reader);
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->andReturn('/fixture/key');
    app()->instance(SshKeyProvider::class, $keys);
    $hosts = Mockery::mock(KnownHostsStore::class);
    $hosts->shouldReceive('path')->andReturn('/fixture/known_hosts');
    app()->instance(KnownHostsStore::class, $hosts);

    return $reader;
}

/** The Swamp vault behind the WebSocket: it answers every secret read with `gateway-session`, keeps every put, and records the transaction level of each frame. */
function swampVaultStandIn(): object
{
    $socket = new class implements WebSocketClient
    {
        /** @var list<array{type: string, key: string, level: int}> */
        public array $frames = [];

        /** @var list<array<string, mixed>> */
        private array $sent = [];

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void {}

        public function send(array $message): void
        {
            $this->sent[] = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            $frame = end($this->sent);
            $key = (string) ($frame['payload']['secretKey'] ?? $frame['payload']['key'] ?? '');
            $this->frames[] = ['type' => (string) $frame['type'], 'key' => $key, 'level' => DB::transactionLevel()];
            $data = ['vaultName' => $frame['payload']['vaultName'] ?? 'orbit-control-inputs', 'secretKey' => $key, 'vaultType' => 'local_encryption'];

            return [['id' => $frame['id'], 'type' => $frame['type'], 'payload' => ['data' => $frame['type'] === 'vault.read-secret' ? [...$data, 'value' => 'gateway-session'] : $data]]];
        }

        public function close(): void {}

        public function isConnected(): bool
        {
            return true;
        }
    };
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'run-token']);

    return $socket;
}

/**
 * Points the Gateway at a recipe whose agent folder pins no Conn Server, for the refusal of a server the recipe lacks
 * (docs/reference/development-containers.md#container-server).
 */
function containerServerConnUnpinned(): void
{
    $recipe = json_decode((string) file_get_contents(resource_path('machine-software/core.yaml')), true, flags: JSON_THROW_ON_ERROR);
    unset($recipe['instances']['agent']['conn-server']);
    $root = sys_get_temp_dir().'/orbit-conn-recipe-'.bin2hex(random_bytes(6));
    mkdir($root.'/release/apps/gateway/resources/machine-software', 0777, true);
    file_put_contents($root.'/release/apps/gateway/resources/machine-software/core.yaml', json_encode($recipe, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    // The Gateway reads the recipe of its checkout when the checkout is a release link.
    symlink($root.'/release', $root.'/orbit');
    config(['orbit.gateway_checkout' => $root.'/orbit/apps/gateway']);
}

/**
 * Swamp serve behind the WebSocket for the native-end job of one Operation: its bound root run ended with `$status`. Returns the
 * frame types it was asked for.
 *
 * @return ArrayObject<int, string>
 */
function nativeRunEnded(Operation $operation, string $run, string $status = 'failed'): ArrayObject
{
    $history = ['id' => $run, 'workflowName' => app(DirectOperation::class)->workflowFor($operation), 'status' => $status,
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => $operation->kind],
        'startedAt' => now()->subMinutes(5)->toISOString(), 'completedAt' => now()->toISOString(), 'jobs' => []];
    $types = new ArrayObject;
    app()->instance(WebSocketClient::class, new class($history, $types) implements WebSocketClient
    {
        private ?array $frame = null;

        private bool $open = false;

        /** @param ArrayObject<int, string> $types */
        public function __construct(private readonly array $history, private readonly ArrayObject $types) {}

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->open = true;
        }

        public function send(array $message): void
        {
            $this->frame = $message;
            $this->types->append((string) $message['type']);
        }

        public function receive(float $timeoutSeconds): array
        {
            [$frame, $this->frame] = [$this->frame, null];
            if ($frame === null) {
                return [];
            }
            $payload = match ($frame['type']) {
                'workflow.history.get' => ['data' => $this->history],
                'workflow.cancel' => ['data' => ['runId' => $this->history['id'], 'status' => 'cancellation_requested']],
            };

            return [['id' => $frame['id'], 'type' => $frame['type'], 'payload' => $payload]];
        }

        public function close(): void
        {
            $this->open = false;
        }

        public function isConnected(): bool
        {
            return $this->open;
        }
    });
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.read_token' => 'read-token', 'swamp.run_token' => 'run-token']);

    return $types;
}
