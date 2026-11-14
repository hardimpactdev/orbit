<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampConnection;
use App\Infrastructure\Swamp\SwampTerminalReadUnavailable;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

afterEach(fn () => app(SwampConnection::class)->release());

/** A failed network phase: the report step succeeded and wrote output version 15, as in Operation 15505. */
function converge_failed_run(string $runId, Operation $operation, ?string $inline): array
{
    $phases = [];
    foreach (['input', 'agent', 'software', 'roles', 'tools', 'network', 'check'] as $name) {
        $phases[] = ['name' => $name, 'status' => match ($name) {
            'network' => 'failed', 'check' => 'skipped', default => 'succeeded'
        },
            'changed' => 0, 'elapsed_seconds' => $name === 'check' ? 0 : 1];
    }
    $report = ['name' => 'Report Node converge', 'status' => 'succeeded',
        'dataArtifacts' => [['dataId' => '346362e7-f71b-40f4-8393-44ec14f015d3', 'name' => 'output', 'version' => 15]]];
    if ($inline !== null) {
        $report['outputs'] = ['stdout' => $inline];
    }

    return ['id' => $runId, 'workflowName' => 'operation-node-converge', 'status' => 'failed',
        'startedAt' => '2026-11-10T00:12:24.612Z', 'completedAt' => '2026-11-10T00:15:16.214Z',
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => 'node.converge'],
        'jobs' => [
            ['name' => 'network', 'status' => 'failed', 'steps' => [['name' => 'Converge network', 'status' => 'failed', 'error' => 'Command exited with code 2']]],
            ['name' => 'check', 'status' => 'skipped', 'steps' => [['name' => 'Check the Node', 'status' => 'skipped']]],
            ['name' => 'converge', 'status' => 'succeeded', 'steps' => [$report]],
        ],
        '_result' => ['outcome' => 'failed', 'phases' => $phases, 'changed' => 0, 'elapsed_seconds' => 6, 'tools' => [], 'browser_trust' => [],
            'cleanup_complete' => true, 'failed_phase' => 'network', 'error_code' => 'operation.native_failed']];
}

/** Answers each read by type: the run history, and the report artifact with or without its content. */
function converge_read_socket(array $run, bool $artifactReadable): WebSocketClient
{
    return new class($run, $artifactReadable) implements WebSocketClient
    {
        private array $frames = [];

        private bool $open = false;

        public function __construct(private readonly array $run, private readonly bool $artifactReadable) {}

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
            $replies = [];
            foreach ($this->frames as $frame) {
                $result = $this->run['_result'];
                $history = array_diff_key($this->run, ['_result' => true]);
                $payload = match ($frame['type']) {
                    'workflow.history.get' => ['data' => $history],
                    'data.get' => ['data' => $this->artifactReadable ? ['content' => json_encode(['stdout' => json_encode($result)])] : []],
                    default => ['data' => ['results' => []]],
                };
                $replies[] = ['id' => $frame['id'], 'type' => $frame['type'], 'payload' => $payload];
            }
            $this->frames = [];

            return $replies;
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
}

beforeEach(function (): void {
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'swamp', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.15', 'public_ssh_host' => '80.47.233.206', 'user' => 'orbit']));
    $this->runId = '673496fe-852d-44e0-9e97-05e19858ac5c';
    $this->operation = Operation::query()->create(['kind' => 'node.converge', 'target_type' => 'node_footprint', 'target_id' => $node->id,
        'node_id' => $node->id, 'status' => OperationStatus::Running, 'lock_key' => 'converge-terminal-read',
        'input' => ['_converge' => ['intent' => ['tools' => []]]], 'state' => ['swamp_run_id' => $this->runId]]);
    $this->selector = app(DirectOperation::class)->definition('node.converge')['terminal_result'];
    app(SwampConnection::class)->release();
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.read_token' => 'read-token']);
});

it('reads again when the report step succeeded but its output could not be read', function (): void {
    app()->instance(WebSocketClient::class, converge_read_socket(converge_failed_run($this->runId, $this->operation, null), false));

    expect(fn () => app(SwampCommandStep::class)->projectTerminal($this->operation, ['id' => $this->runId, 'status' => 'failed'], $this->selector))
        ->toThrow(SwampTerminalReadUnavailable::class);
    expect($this->operation->refresh()->status)->toBe(OperationStatus::Running);
});

it('projects the failed phase once the report output is read', function (bool $inline): void {
    $run = converge_failed_run($this->runId, $this->operation, null);
    if ($inline) {
        $run = converge_failed_run($this->runId, $this->operation, json_encode($run['_result']));
    }
    app()->instance(WebSocketClient::class, converge_read_socket($run, true));

    $operation = app(SwampCommandStep::class)->projectTerminal($this->operation, ['id' => $this->runId, 'status' => 'failed'], $this->selector);

    expect($operation->status)->toBe(OperationStatus::Failed)
        ->and($operation->error_code)->toBe('operation.native_failed')
        ->and($operation->result['failed_phase'] ?? null)->toBe('network');
})->with(['inline output' => [true], 'output artifact' => [false]]);
