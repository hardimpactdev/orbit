<?php

declare(strict_types=1);

use App\Actions\Instances\StartDevelopmentInstanceCreateAction;
use App\Actions\Nodes\AllocateInstanceBridgeAction;
use App\Data\Instances\CreateInstanceData;
use App\Domain\Instances\ContainerNodes;
use App\Domain\Nodes\RoleName;
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
        config(['instances.container_nodes' => [...app(ContainerNodes::class)->ids(), $node->id]]);
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
        /** @var list<array{arguments: list<string>, level: int}> */
        public array $calls = [];

        public function __construct(public ?string $answer) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->calls[] = ['arguments' => $command->arguments, 'level' => DB::transactionLevel()];

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
