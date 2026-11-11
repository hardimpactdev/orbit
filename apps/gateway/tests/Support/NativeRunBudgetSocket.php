<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Operations\DirectOperation;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\SwampConnection;
use App\Models\Operation;

/** Pinned native wire shapes, with test-owned records instead of any live connection. */
final class NativeRunBudgetSocket implements WebSocketClient
{
    /** @var list<array<string,mixed>> */
    public array $jobs = [];

    /** @var array<string,array<string,mixed>> */
    public array $children = [];

    /** @var list<array<string,mixed>> */
    public array $frames = [];

    public bool $unavailable = false;

    public bool $incompleteSearch = false;

    private bool $open = false;

    /** @var array<string,mixed>|null */
    private ?array $frame = null;

    public function __construct(private readonly Operation $operation) {}

    public static function install(Operation $operation): self
    {
        $socket = new self($operation);
        app(SwampConnection::class)->release();
        app()->instance(WebSocketClient::class, $socket);
        config(['swamp.run_token' => 'run-token', 'swamp.read_token' => 'read-token', 'swamp.address' => '10.44.0.15', 'swamp.ca_path' => __FILE__]);

        return $socket;
    }

    public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
    {
        $this->open = true;
    }

    public function send(array $message): void
    {
        $this->frame = $message;
        $this->frames[] = $message;
    }

    public function receive(float $timeoutSeconds): array
    {
        $frame = $this->frame;
        $this->frame = null;
        if ($frame === null) {
            return [];
        }
        if ($this->unavailable) {
            return [['id' => $frame['id'], 'type' => 'error', 'error' => ['code' => 'not_found']]];
        }
        $operation = $this->operation->refresh();
        $runId = $operation->state['swamp_run_id'] ?? null;
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/SwampRunBudgets/db3f6373.json')), true, flags: JSON_THROW_ON_ERROR);
        $run = [...$fixture['cases']['running']['history']['payload']['data'], 'id' => $runId,
            'workflowName' => app(DirectOperation::class)->definition($operation->kind)['workflow'],
            'startedAt' => $operation->started_at?->toISOString(), 'jobs' => $this->jobs,
            'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => $operation->kind]];
        $data = match ($frame['type']) {
            'workflow.history.get' => ($frame['payload']['workflowIdOrName'] ?? null) === $runId ? $run : ($this->children[$frame['payload']['workflowIdOrName']] ?? null),
            'workflow.run.search' => $this->incompleteSearch ? [] : ['results' => array_map(static fn (array $child): array => ['runId' => $child['id'], 'parentRun' => $child['parentRun']], array_values($this->children))],
            'workflow.cancel' => ['runId' => $runId, 'status' => 'cancelled'],
            default => [],
        };

        return [['id' => $frame['id'], 'type' => $frame['type'], 'payload' => ['data' => $data]]];
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function isConnected(): bool
    {
        return $this->open;
    }
}
