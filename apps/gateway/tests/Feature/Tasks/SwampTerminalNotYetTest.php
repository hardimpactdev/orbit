<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampConnection;
use App\Infrastructure\Swamp\SwampTerminalReadUnavailable;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

afterEach(fn () => app(SwampConnection::class)->release());

it('reads again when the run history is not terminal yet', function (): void {
    $run = '986bc349-54dc-4dea-9f22-76e3353d1ca0';
    $operation = Operation::query()->create(['kind' => 'tasks.prep', 'target_type' => 'task_group', 'target_id' => 1, 'node_id' => null,
        'status' => OperationStatus::Running, 'state' => ['swamp_run_id' => $run], 'input' => []]);
    $direct = app(DirectOperation::class);
    $definition = $direct->definition('tasks.prep');
    $socket = new class($operation->id, $definition['workflow']) implements WebSocketClient
    {
        private ?array $frame = null;

        private bool $open = false;

        public function __construct(private readonly int $operationId, private readonly string $workflow) {}

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->open = true;
        }

        public function send(array $message): void
        {
            $this->frame = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            $frame = $this->frame;
            $this->frame = null;

            return $frame === null ? [] : [['id' => $frame['id'], 'type' => $frame['type'], 'payload' => ['data' => [
                'id' => $frame['payload']['workflowIdOrName'], 'workflowName' => $this->workflow, 'status' => 'running',
                'inputs' => ['operation_id' => $this->operationId, 'node_id' => null, 'kind' => 'tasks.prep'],
            ]]]];
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
    app(SwampConnection::class)->release();
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.read_token' => 'read-token']);

    expect(fn () => app(SwampCommandStep::class)->projectTerminal($operation, ['id' => $run], $definition['terminal_result']))
        ->toThrow(SwampTerminalReadUnavailable::class);
});
