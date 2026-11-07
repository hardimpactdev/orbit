<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\SwampRunSubscriber;
use App\Jobs\Operations\ProjectSwampOperation;
use App\Jobs\Operations\ReconcileSwampOperations;
use App\Models\Operation;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Psr\Log\NullLogger;

uses(LazilyRefreshDatabase::class);

/** A run connection that accepts an attach and then reports the run as ended. */
function endedRunSocket(string $workflow, string $status): WebSocketClient
{
    return new class($workflow, $status) implements WebSocketClient
    {
        /** @var list<array<string,mixed>> */
        public array $frames = [];

        /** @var list<array<string,mixed>> */
        private array $queue = [];

        private bool $open = false;

        public function __construct(private readonly string $workflow, private readonly string $status) {}

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
                    'run' => ['id' => $run, 'workflowName' => $this->workflow, 'status' => $this->status]]];
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

it('queues the projection of the Operation when a followed run ends, and projects nothing itself', function (): void {
    Queue::fake();
    $run = '986bc349-54dc-4dea-9f22-76e3353d1ca1';
    $operation = Operation::query()->create(['kind' => 'tasks.prep', 'target_type' => 'task_group', 'target_id' => 1, 'node_id' => null,
        'status' => OperationStatus::Running, 'state' => ['swamp_run_id' => $run], 'input' => []]);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => __FILE__, 'swamp.run_token' => 'run-token']);
    $subscriber = new SwampRunSubscriber(endedRunSocket(app(DirectOperation::class)->definition('tasks.prep')['workflow'], 'succeeded'), new NullLogger);

    $subscriber->follow($operation->id);
    $subscriber->pass();
    $subscriber->pass();

    Queue::assertPushed(ProjectSwampOperation::class, fn (ProjectSwampOperation $job): bool => $job->operationId === $operation->id);
    Queue::assertPushed(ProjectSwampOperation::class, 1);
    expect($operation->refresh()->status)->toBe(OperationStatus::Running);
});

it('queues the projection while a reconcile pass runs or waits and holds its unique lock', function (): void {
    Queue::fake();
    expect((new UniqueLock(app(Repository::class)))->acquire(new ReconcileSwampOperations))->toBeTrue();
    $run = '986bc349-54dc-4dea-9f22-76e3353d1ca2';
    $operation = Operation::query()->create(['kind' => 'tasks.prep', 'target_type' => 'task_group', 'target_id' => 1, 'node_id' => null,
        'status' => OperationStatus::Running, 'state' => ['swamp_run_id' => $run], 'input' => []]);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => __FILE__, 'swamp.run_token' => 'run-token']);
    $subscriber = new SwampRunSubscriber(endedRunSocket(app(DirectOperation::class)->definition('tasks.prep')['workflow'], 'succeeded'), new NullLogger);

    $subscriber->follow($operation->id);
    $subscriber->pass();

    Queue::assertPushed(ProjectSwampOperation::class, fn (ProjectSwampOperation $job): bool => $job->operationId === $operation->id);
});
