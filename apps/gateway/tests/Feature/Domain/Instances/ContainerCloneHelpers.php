<?php

declare(strict_types=1);

use App\Actions\Instances\RecordInstanceReadyAction;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Projects\ProjectType;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;

require_once __DIR__.'/ContainerInstanceHelpers.php';

/* The seeded default, the clone result and the vault of the container clone tests, shared by their files. */

/** The Project's container default on the Node, active with its seed snapshot, as its finished create leaves it. */
function seededDefault(Node $node, ?ProjectType $type = null): Instance
{
    $project = containerDefaultProject($type ?? ProjectType::NodePackage);
    $default = Instance::query()->findOrFail(containerCreate($project, $node)->target_id);
    $default->update(['status' => InstanceState::Active, 'first_setup_pending' => false]);
    InstanceRuntime::query()->where('instance_id', $default->id)->firstOrFail()
        ->update(['seed_commit' => str_repeat('c', 40), 'power_state' => InstanceRuntimePowerState::Running]);

    return $default->refresh();
}

/** @return array<string, mixed> */
function cloneSucceeded(Operation $operation): array
{
    return ['status' => 'succeeded', 'run_id' => 'run-1', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['ready' => true, 'instance_id' => $operation->target_id, 'commit' => str_repeat('c', 40), 't3' => [
            'environment_id' => 'env-feature', 'server_version' => '0.1.7', 'expires_at' => '2026-12-10T10:00:00.000Z',
            'gateway_session' => ['vault' => 'orbit-control-inputs', 'key' => 'nckrtl-instance-t3-model-register-session-gateway-1-token'],
            'swamp_session' => ['vault' => 'orbit-control-inputs', 'key' => 'nckrtl-instance-t3-model-register-session-swamp-1-token']]]];
}

/**
 * A Swamp vault behind the WebSocket: it keeps what the Gateway puts and answers reads of `$secrets`.
 *
 * @param  array<string, string>  $secrets
 */
function vaultReadingSocket(array $secrets): object
{
    $socket = new class($secrets) implements WebSocketClient
    {
        /** @var list<string> */
        public array $reads = [];

        /** @var list<array{key: string, value: string}> */
        public array $puts = [];

        /** @var list<array<string, mixed>> */
        private array $frames = [];

        private bool $open = false;

        /** @param array<string, string> $secrets */
        public function __construct(private array $secrets) {}

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->open = true;
        }

        public function send(array $message): void
        {
            $this->frames[] = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            $frame = end($this->frames);
            if ($frame['type'] === 'vault.read-secret') {
                $this->reads[] = $frame['payload']['secretKey'];

                return [['id' => $frame['id'], 'type' => 'vault.read-secret', 'payload' => ['data' => ['vaultName' => $frame['payload']['vaultName'],
                    'secretKey' => $frame['payload']['secretKey'], 'vaultType' => 'local_encryption', 'value' => $this->secrets[$frame['payload']['secretKey']] ?? '']]]];
            }
            $this->puts[] = ['key' => $frame['payload']['key'], 'value' => (string) ($frame['payload']['value'] ?? '')];

            return [['id' => $frame['id'], 'type' => 'vault.put', 'payload' => ['data' => [
                'vaultName' => $frame['payload']['vaultName'], 'secretKey' => $frame['payload']['key'], 'vaultType' => 'local_encryption']]]];
        }

        public function close(): void
        {
            $this->open = false;
        }

        public function isConnected(): bool
        {
            return $this->open;
        }
    };
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'run-token']);

    return $socket;
}

/**
 * The Node behind the workspace reader: it answers every call with `$answer` and counts the calls.
 *
 * @param  array<string, mixed>|null  $answer  the reader's JSON, or null for a failed call
 */
function workspaceReaderNode(?array $answer): object
{
    $ssh = new class($answer) implements SshExecutor
    {
        public int $calls = 0;

        /** @param array<string, mixed>|null $answer */
        public function __construct(public ?array $answer) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->calls++;

            return $this->answer === null ? new CommandResult(255, '', 'unreachable', 5, false)
                : new CommandResult(0, json_encode($this->answer, JSON_THROW_ON_ERROR), '', 5, false);
        }
    };
    app()->instance(SshExecutor::class, $ssh);
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->andReturn('/fixture/key');
    app()->instance(SshKeyProvider::class, $keys);
    $hosts = Mockery::mock(KnownHostsStore::class);
    $hosts->shouldReceive('path')->andReturn('/fixture/known_hosts');
    app()->instance(KnownHostsStore::class, $hosts);

    return $ssh;
}

/** The workspace baseline the reader answers in these tests. */
function readyBaseline(): array
{
    return ['heads' => [str_repeat('a', 40)], 'stashes' => [], 'untracked' => ['notes.txt']];
}

/** The create run's ready mark, as its step after `clone-register` takes it, with the `t3` of a succeeded run. */
function markCloneReady(Operation $operation): string
{
    return app(RecordInstanceReadyAction::class)->execute($operation->refresh(), 'run-1', cloneSucceeded($operation)['result']['t3']);
}
