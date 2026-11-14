<?php

declare(strict_types=1);

use App\Actions\Operations\CancelOperationAction;
use App\Actions\Operations\ExpireOperationAction;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Tasks\TaskGroupStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\SwampConnection;
use App\Jobs\Operations\ReconcileSwampOperations;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

afterEach(fn () => app(SwampConnection::class)->release());

const UNREADABLE_RUN = '5d0c3a8e-4f7b-4a51-9a63-0e2b1c7d9f10';

/** A tasks.publish of a task group, bound to its run and holding the group, as Operation 20763 was. */
function unreadablePublish(int $startedMinutesAgo): Operation
{
    $group = Task::query()->create(['title' => 'Copy', 'brief' => 'Publish it', 'status' => TaskGroupStatus::Running]);
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'beast-'.$group->id, 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit']));
    $node->roles()->firstOrCreate(['role' => 'gateway'], ['status' => 'active']);
    // One earlier 40-second success: the learned budget is its 900-second floor, as on production.
    Operation::query()->create(['kind' => 'tasks.publish', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $node->id,
        'input' => [], 'status' => OperationStatus::Succeeded, 'state' => ['swamp_run_id' => '0b6f7d55-8f0e-4c3a-b1a4-7c2e9d3f5a61'], 'sequence' => 1,
        'attempts' => 1, 'started_at' => now()->subDay(), 'finished_at' => now()->subDay()->addSeconds(40)]);

    return Operation::query()->create(['kind' => 'tasks.publish', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $node->id,
        'lock_key' => OperationStore::lockKey('task_group', $group->id), 'input' => ['branch' => 'task/4987', 'commit' => str_repeat('a', 40)],
        'status' => OperationStatus::Running, 'state' => ['swamp_run_id' => UNREADABLE_RUN], 'sequence' => 1, 'attempts' => 1,
        'started_at' => now()->subMinutes($startedMinutesAgo)]);
}

/**
 * Serve's answers: the run's history with "Confirm publication" in the status the test names, and an error frame for a cancel,
 * as Swamp answers for a run that has ended.
 *
 * @return WebSocketClient&object{frames: list<array<string,mixed>>}
 */
function unreadableSocket(Operation $operation, string $runStatus, ?string $endCheck, int $completedMinutesAgo,
    string $workflow = 'operation-task-publish', string $job = 'cleanup', string $step = 'Confirm publication'): WebSocketClient
{
    $steps = [['name' => 'Revoke the token', 'status' => 'succeeded'], ['name' => 'Remove remote scratch', 'status' => 'skipped']];
    if ($endCheck !== null) {
        $steps[] = ['name' => $step, 'status' => $endCheck];
    }
    $history = ['id' => UNREADABLE_RUN, 'workflowName' => $workflow, 'status' => $runStatus,
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => $operation->kind],
        'startedAt' => $operation->started_at?->toIso8601ZuluString('millisecond'),
        'completedAt' => in_array($runStatus, ['running', 'pending'], true) ? null : now()->subMinutes($completedMinutesAgo)->toIso8601ZuluString('millisecond'),
        'jobs' => [['name' => $job, 'status' => $runStatus, 'steps' => $steps]]];
    $socket = new class($history) implements WebSocketClient
    {
        /** @var list<array<string,mixed>> */
        public array $frames = [];

        private bool $open = false;

        /** @param array<string,mixed> $history */
        public function __construct(private readonly array $history) {}

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
            if ($frame === false) {
                return [];
            }

            return [['id' => $frame['id'], ...match ($frame['type']) {
                'workflow.history.get' => ['type' => 'workflow.history.get', 'payload' => ['data' => $this->history]],
                'workflow.cancel' => ['type' => 'error', 'error' => ['code' => 'workflow_not_cancellable']],
                default => ['type' => $frame['type'], 'payload' => ['data' => ['results' => []]]],
            }]];
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
    config(['swamp.run_token' => 'run-token', 'swamp.read_token' => 'read-token', 'swamp.address' => '10.44.0.15', 'swamp.ca_path' => __FILE__]);

    return $socket;
}

function unreadablePass(): void
{
    app()->call([new ReconcileSwampOperations, 'handle']);
}

beforeEach(fn () => Queue::fake());

it('fails at once, frees its target and names the end check when the run succeeded but its end check was skipped', function (): void {
    $operation = unreadablePublish(startedMinutesAgo: 2);
    $socket = unreadableSocket($operation, 'succeeded', 'skipped', completedMinutesAgo: 1);

    unreadablePass();

    $operation->refresh();
    expect($operation->status)->toBe(OperationStatus::Failed)
        ->and($operation->error_code)->toBe('operation.result_unreadable')
        ->and($operation->result)->toBe(['error_code' => 'operation.result_unreadable'])
        ->and($operation->lock_key)->toBeNull()
        ->and($operation->failed_step)->toBe('Confirm publication')
        ->and($operation->finished_at?->toIso8601ZuluString())->toBe(now()->subMinute()->toIso8601ZuluString())
        ->and(array_column($socket->frames, 'type'))->not->toContain('workflow.cancel');

    // What the API and the CLI show.
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2'])->getJson('/api/v1/operations/'.$operation->id)->assertOk()
        ->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error_code', 'operation.result_unreadable')
        ->assertJsonPath('data.failed_step', 'Confirm publication')
        ->assertJsonPath('data.error.detail', 'The run succeeded, but its end check "Confirm publication" is skipped, so its result cannot be read.');
});

it('names an end check that the history does not hold', function (): void {
    $operation = unreadablePublish(startedMinutesAgo: 2);
    unreadableSocket($operation, 'succeeded', null, completedMinutesAgo: 1);

    unreadablePass();

    expect($operation->refresh()->error_code)->toBe('operation.result_unreadable')
        ->and($operation->state['native_failure']['detail'])->toContain('"Confirm publication" is absent');
});

it('settles a cancel of a run that has already ended in the next pass, and sends Swamp no cancel', function (): void {
    $operation = unreadablePublish(startedMinutesAgo: 2);
    $socket = unreadableSocket($operation, 'succeeded', 'skipped', completedMinutesAgo: 1);
    app(CancelOperationAction::class)->execute($operation);
    expect($operation->refresh()->cancel_requested_at)->not->toBeNull();

    unreadablePass();

    $operation->refresh();
    expect($operation->status)->toBe(OperationStatus::Failed)
        ->and($operation->error_code)->toBe('operation.result_unreadable')
        ->and($operation->lock_key)->toBeNull()
        ->and(array_column($socket->frames, 'type'))->not->toContain('workflow.cancel');
});

it('frees the lock of an expired Operation whose ended run has a skipped end check, once, with no reopen loop', function (): void {
    // Operation 20763: it started 20 minutes ago, its run ended after 1 minute, and it expired at its 900-second budget.
    $operation = unreadablePublish(startedMinutesAgo: 20);
    $socket = unreadableSocket($operation, 'succeeded', 'skipped', completedMinutesAgo: 19);
    app(ExpireOperationAction::class)->execute($operation);
    $operation->refresh();
    expect($operation->error_code)->toBe('operation.timed_out')->and($operation->lock_key)->not->toBeNull();

    unreadablePass();

    $operation->refresh();
    $sequence = $operation->sequence;
    expect($operation->status)->toBe(OperationStatus::Failed)
        ->and($operation->error_code)->toBe('operation.result_unreadable')
        ->and($operation->lock_key)->toBeNull()
        ->and($operation->failed_step)->toBe('Confirm publication')
        ->and($operation->finished_at?->toIso8601ZuluString())->toBe(now()->subMinutes(19)->toIso8601ZuluString())
        ->and($operation->state)->not->toHaveKey('native_timeout');

    $this->travel(2)->minutes();
    unreadablePass();

    expect($operation->refresh()->sequence)->toBe($sequence)
        ->and(array_column($socket->frames, 'type'))->not->toContain('workflow.cancel');
});

it('keeps the budget of a run that still runs', function (): void {
    $operation = unreadablePublish(startedMinutesAgo: 2);
    unreadableSocket($operation, 'running', null, completedMinutesAgo: 0);

    unreadablePass();

    expect($operation->refresh()->status)->toBe(OperationStatus::Running)
        ->and($operation->lock_key)->not->toBeNull();
});

it('settles a run whose end check succeeded as before', function (): void {
    $operation = unreadablePublish(startedMinutesAgo: 2);
    unreadableSocket($operation, 'succeeded', 'succeeded', completedMinutesAgo: 1);

    unreadablePass();

    $operation->refresh();
    expect($operation->status)->toBe(OperationStatus::Succeeded)
        ->and($operation->error_code)->toBeNull()
        ->and($operation->lock_key)->toBeNull()
        ->and($operation->result['branch'] ?? null)->toBe('task/4987');
});

it('keeps the timeout of a run that ended after its budget', function (): void {
    $operation = unreadablePublish(startedMinutesAgo: 20);
    unreadableSocket($operation, 'succeeded', 'skipped', completedMinutesAgo: 1);

    unreadablePass();

    $operation->refresh();
    expect($operation->error_code)->toBe('operation.timed_out')
        ->and($operation->lock_key)->toBeNull()
        ->and($operation->state['native_timeout']['native_terminal_status'])->toBe('succeeded');
});

it('moves the records of a family that leaves its in-progress state at a timeout, with the new code', function (): void {
    $operation = unreadablePublish(startedMinutesAgo: 2);
    $node = Node::query()->findOrFail($operation->node_id);
    $operation->update(['kind' => 'node.remove', 'target_type' => 'node', 'target_id' => $node->id, 'lock_key' => 'node:'.$node->id,
        'input' => ['_node_removal' => ['node_id' => $node->id, 'roles' => []]]]);
    unreadableSocket($operation, 'succeeded', 'skipped', completedMinutesAgo: 1, workflow: 'operation-node-remove', job: 'remove', step: 'Confirm Node removal');

    unreadablePass();

    expect($operation->refresh()->error_code)->toBe('operation.result_unreadable')
        ->and($operation->lock_key)->toBeNull()
        ->and($node->refresh()->error_code)->toBe('operation.result_unreadable')
        ->and($node->failed_step)->toBe('remove:operation');
});
