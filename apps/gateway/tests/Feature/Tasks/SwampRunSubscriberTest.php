<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\SwampRunSubscriber;
use App\Jobs\Operations\ReconcileSwampOperations;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Psr\Log\NullLogger;

uses(LazilyRefreshDatabase::class);

/** A run connection that accepts an attach and then reports the run as ended. */
function endedRunSocket(string $workflow, string $status, bool $child = false): WebSocketClient
{
    return new class($workflow, $status, $child) implements WebSocketClient
    {
        /** @var list<array<string,mixed>> */
        public array $frames = [];

        /** @var list<array<string,mixed>> */
        private array $queue = [];

        private bool $open = false;

        public function __construct(private readonly string $workflow, private readonly string $status, private readonly bool $child) {}

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->open = true;
        }

        public function send(array $message): void
        {
            $this->frames[] = $message;
            if ($message['type'] === 'run.attach') {
                $run = $message['payload']['runId'];
                $this->queue[] = ['id' => $message['id'], 'type' => 'run.attached', 'payload' => ['runId' => $run, 'kind' => 'workflow-run']];
                $this->queue[] = ['id' => $message['id'], 'type' => 'event', 'event' => ['seq' => 1, 'kind' => 'completed',
                    ...($this->child ? ['parentRunId' => $run] : []),
                    'run' => ['id' => $this->child ? '986bc349-54dc-4dea-9f22-76e3353d1cf0' : $run, 'workflowName' => $this->workflow, 'status' => $this->status]]];
            }
        }

        public function receive(float $timeoutSeconds): array
        {
            $frames = $this->queue;
            $this->queue = [];

            return $frames;
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

it('asks the reconciler for a pass when a followed run ends, and projects nothing itself', function (): void {
    Queue::fake();
    $run = '986bc349-54dc-4dea-9f22-76e3353d1ca1';
    $operation = Operation::query()->create(['kind' => 'tasks.prep', 'target_type' => 'task_group', 'target_id' => 1, 'node_id' => null,
        'status' => OperationStatus::Running, 'state' => ['swamp_run_id' => $run], 'input' => []]);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => __FILE__, 'swamp.run_token' => 'run-token']);
    $subscriber = new SwampRunSubscriber(endedRunSocket(app(DirectOperation::class)->definition('tasks.prep')['workflow'], 'succeeded'), new NullLogger);

    $subscriber->follow($operation->id);
    $subscriber->pass();
    $subscriber->pass();

    Queue::assertPushed(ReconcileSwampOperations::class, 1);
    expect($operation->refresh()->status)->toBe(OperationStatus::Running);
});

it('requests child evidence reconciliation and keeps following the parent root', function (): void {
    Queue::fake();
    $run = '986bc349-54dc-4dea-9f22-76e3353d1cf1';
    $operation = Operation::query()->create(['kind' => 'tasks.prep', 'target_type' => 'task_group', 'target_id' => 1, 'node_id' => null,
        'status' => OperationStatus::Running, 'state' => ['swamp_run_id' => $run], 'input' => []]);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => __FILE__, 'swamp.run_token' => 'run-token']);
    $socket = endedRunSocket('operation-task-turn', 'succeeded', true);
    $subscriber = new SwampRunSubscriber($socket, new NullLogger);
    $subscriber->follow($operation->id);
    $subscriber->pass();
    Queue::assertPushed(ReconcileSwampOperations::class, 1);
    expect($operation->refresh()->status)->toBe(OperationStatus::Running);
    $subscriber->close();
    $subscriber->pass();
    expect($socket->frames)->toHaveCount(2);
    expect($socket->frames[1]['payload']['runId'])->toBe($run);
});
