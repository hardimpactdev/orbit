<?php

declare(strict_types=1);

use App\Actions\Operations\ExpireOperationAction;
use App\Data\Operations\OperationData;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Domain\Releases\ReleaseAlertStep;
use App\Domain\Releases\ReleaseAlertWebhook;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampConnection;
use App\Jobs\Operations\ReconcileSwampOperations;
use App\Jobs\Operations\SendOperationOverdueAlert;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;

uses(LazilyRefreshDatabase::class);

function overdueReportingOperation(bool $bound = true, int $target = 42): Operation
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->firstOrCreate(['name' => 'gateway'], ['status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit']));
    $node->roles()->firstOrCreate(['role' => 'gateway'], ['status' => 'active']);

    return Operation::query()->create(['kind' => 'tasks.script', 'target_type' => 'task_group', 'target_id' => $target, 'node_id' => $node->id,
        'input' => ['run_budget_seconds' => 900], 'status' => OperationStatus::Running, 'attempts' => $bound ? 1 : 0,
        'sequence' => 1, 'lock_key' => 'task_group:'.$target, 'started_at' => now(), 'current_step' => 'work',
        'state' => $bound ? ['swamp_run_id' => 'ea55ab37-bcbe-4459-81dc-cbb397e7d870'] : []]);
}

it('reports overdue only when the unchanged run-budget cancellation fires', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-11T10:00:00Z'));
    Queue::fake();
    $webhook = Mockery::mock(ReleaseAlertWebhook::class);
    $webhook->shouldReceive('send')->once()->withArgs(fn (array $payload): bool => $payload['event'] === 'operation.overdue'
        && $payload['overdue_since'] === '2026-11-11T10:15:00.000000Z' && $payload['run_budget_seconds'] === 900
        && ! isset($payload['input']))->andReturn(ReleaseAlertStep::done());
    app()->instance(ReleaseAlertWebhook::class, $webhook);
    $operation = overdueReportingOperation();
    $this->travel(15)->minutes();

    $current = app(ExpireOperationAction::class)->execute($operation);

    expect($current->status)->toBe(OperationStatus::Failed)
        ->and($current->error_code)->toBe('operation.timed_out')
        ->and($current->cancel_requested_at)->not->toBeNull()
        ->and($current->state['native_cancel_requested_at'] ?? null)->not->toBeNull()
        ->and($current->state['native_timeout'])->toMatchArray(['budget_seconds' => 900, 'deadline' => '2026-11-11T10:15:00.000000Z'])
        ->and($current->state['overdue_since'] ?? null)->toBe('2026-11-11T10:15:00.000000Z')
        ->and($current->sequence)->toBe(3);
    expect(Activity::query()->where('command', 'operation:overdue-alert')->count())->toBe(0);
    Queue::assertPushed(ReconcileSwampOperations::class, 1);
    Queue::assertPushedOn('tasks', SendOperationOverdueAlert::class);
    app()->call([Queue::pushed(SendOperationOverdueAlert::class)->sole(), 'handle']);
    expect(Activity::query()->where('command', 'operation:overdue-alert')->sole()->status)->toBe('succeeded');
});

it('does not mark or alert before the existing cancellation deadline', function (): void {
    $this->freezeTime();
    Queue::fake();
    $operation = overdueReportingOperation();
    $this->travel(899)->seconds();

    $current = app(ExpireOperationAction::class)->execute($operation);

    expect($current->status)->toBe(OperationStatus::Running)->and($current->cancel_requested_at)->toBeNull()
        ->and($current->state)->not->toHaveKey('overdue_since');
    expect(Activity::query()->where('command', 'operation:overdue-alert')->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('does not repeat the alert on a stale object or a later read after webhook failure', function (): void {
    $this->freezeTime();
    Queue::fake();
    $webhook = Mockery::mock(ReleaseAlertWebhook::class);
    $webhook->shouldReceive('send')->once()->andReturn(ReleaseAlertStep::failed('unavailable'));
    app()->instance(ReleaseAlertWebhook::class, $webhook);
    $operation = overdueReportingOperation();
    $this->travel(16)->minutes();
    app(ExpireOperationAction::class)->execute($operation);
    app()->call([Queue::pushed(SendOperationOverdueAlert::class)->sole(), 'handle']);

    app(ExpireOperationAction::class)->execute($operation);
    app(ExpireOperationAction::class)->execute($operation->refresh());

    expect(Activity::query()->where('command', 'operation:overdue-alert')->sole()->error_code)->toBe('operation.alert_webhook_failed');
    expect($operation->refresh()->sequence)->toBe(3);
    Queue::assertPushed(SendOperationOverdueAlert::class, 1);
});

it('queues delivery only after commit and discards it on rollback', function (): void {
    $this->freezeTime();
    $operation = overdueReportingOperation();
    $this->travel(16)->minutes();
    $webhook = Mockery::mock(ReleaseAlertWebhook::class);
    $webhook->shouldReceive('send')->once()->andReturn(ReleaseAlertStep::done());
    app()->instance(ReleaseAlertWebhook::class, $webhook);

    DB::beginTransaction();
    app(ExpireOperationAction::class)->execute($operation);
    expect(DB::table('jobs')->where('queue', 'tasks')->count())->toBe(0);
    DB::rollBack();
    expect($operation->refresh()->state)->not->toHaveKey('overdue_since');
    expect(DB::table('jobs')->where('queue', 'tasks')->count())->toBe(0);

    app(ExpireOperationAction::class)->execute($operation);

    expect(DB::table('jobs')->where('queue', 'tasks')->count())->toBe(1);
    expect(Activity::query()->where('command', 'operation:overdue-alert')->count())->toBe(0);
    $job = Queue::connection('gateway')->pop('tasks');
    expect($job)->not->toBeNull()->and($job->payload()['maxTries'])->toBe(1);
    $job->fire();
    expect(Activity::query()->where('command', 'operation:overdue-alert')->sole()->status)->toBe('succeeded');
    expect(DB::table('jobs')->where('queue', 'tasks')->count())->toBe(0);
});

it('forwards all cancellations before sixteen slow webhook deliveries', function (): void {
    $this->freezeTime();
    Sleep::fake();
    Queue::fake();
    $webhook = Mockery::mock(ReleaseAlertWebhook::class);
    $webhook->shouldReceive('send')->times(16)->andReturnUsing(function (): ReleaseAlertStep {
        Sleep::for(10)->seconds();

        return ReleaseAlertStep::failed('unreachable');
    });
    app()->instance(ReleaseAlertWebhook::class, $webhook);
    $runs = [];
    foreach (range(1, 16) as $target) {
        $operation = overdueReportingOperation(target: $target);
        $run = (string) Str::uuid();
        $operation->update(['state' => ['swamp_run_id' => $run]]);
        $runs[$run] = ['id' => $run, 'workflowName' => 'operation-task-script', 'status' => 'running',
            'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => 'tasks.script'],
            'startedAt' => now()->toISOString(), 'completedAt' => null, 'jobs' => []];
    }
    $this->travel(16)->minutes();

    $message = [];
    $connected = false;
    $forwarded = 0;
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect')->andReturnUsing(function () use (&$connected): void {
        $connected = true;
    });
    $socket->shouldReceive('close')->andReturnUsing(function () use (&$connected): void {
        $connected = false;
    });
    $socket->shouldReceive('isConnected')->andReturnUsing(function () use (&$connected): bool {
        return $connected;
    });
    $socket->shouldReceive('send')->andReturnUsing(function (array $frame) use (&$message): void {
        $message = $frame;
    });
    $socket->shouldReceive('receive')->andReturnUsing(function () use (&$message, &$forwarded, $runs): array {
        if ($message['type'] === 'workflow.cancel') {
            $forwarded++;

            return [['id' => $message['id'], 'type' => 'workflow.cancel', 'payload' => ['data' => [
                'runId' => $message['payload']['runId'], 'status' => 'cancellation_requested']]]];
        }

        return [['id' => $message['id'], 'type' => 'workflow.history.get',
            'payload' => ['data' => $runs[$message['payload']['workflowIdOrName']]]]];
    });
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'run-token', 'swamp.read_token' => 'read-token', 'swamp.address' => '10.44.0.15', 'swamp.ca_path' => __FILE__]);
    try {
        app()->call([new ReconcileSwampOperations, 'handle']);

        expect($forwarded)->toBe(16);
        expect(Operation::query()->whereNotNull('state->native_cancel_confirmed_at')->count())->toBe(16);
        Sleep::assertNeverSlept();
        Queue::assertPushed(SendOperationOverdueAlert::class, 16);
        Queue::assertPushed(ReconcileSwampOperations::class);
        foreach (Queue::pushed(SendOperationOverdueAlert::class) as $job) {
            expect($job->connection)->toBe('gateway')->and($job->queue)->toBe('tasks');
            app()->call([$job, 'handle']);
        }
        Sleep::assertSleptTimes(16);
        expect(Activity::query()->where('error_code', 'operation.alert_webhook_failed')->count())->toBe(16);
    } finally {
        app(SwampConnection::class)->release();
        Sleep::fake(false);
    }
});

it('keeps admission timeout behavior without an execution overdue alert', function (): void {
    $this->freezeTime();
    Queue::fake();
    $operation = overdueReportingOperation(bound: false);
    $this->travel(601)->seconds();

    $current = app(ExpireOperationAction::class)->execute($operation);

    expect($current->status)->toBe(OperationStatus::Failed)->and($current->error_code)->toBe('operation.timed_out')
        ->and($current->state)->not->toHaveKey('overdue_since');
    expect(Activity::query()->where('command', 'operation:overdue-alert')->count())->toBe(0);
});

it('keeps the native task timeout floor after a quick success', function (string $kind): void {
    $this->freezeTime();
    $success = overdueReportingOperation();
    $success->update(['kind' => $kind, 'status' => OperationStatus::Succeeded, 'lock_key' => null,
        'started_at' => now()->subHour()->subSeconds(56), 'finished_at' => now()->subHour()]);

    expect(OperationRunBudgets::historySeconds($kind))->toBe(900);
    if ($kind === 'tasks.script') {
        expect(OperationRunBudgets::scriptBudget())->toBe(900);
    }
})->with(['tasks.script', 'tasks.check']);

it('records overdue_since through the existing failed API response', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-11T10:00:00Z'));
    Queue::fake();
    $operation = overdueReportingOperation();
    $this->travel(16)->minutes();
    app(ExpireOperationAction::class)->execute($operation);

    $body = $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2'])->getJson('/api/v1/operations/'.$operation->id)
        ->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error_code', 'operation.timed_out')
        ->assertJsonPath('data.cancel_requested', true)->assertJsonPath('data.overdue_since', '2026-11-11T10:15:00.000000Z')->json();
    $this->getJson('/api/v1/operations')->assertOk()
        ->assertJsonPath('data.0.overdue_since', '2026-11-11T10:15:00.000000Z');

    $body['meta']['request_id'] = '0198e15c-bf97-7c23-8f1f-61b8fe67a846';
    $fixture = ['schema' => 1, 'request' => ShowOperationRequest::class, 'route' => 'GET /api/v1/operations/{operation}', 'status' => 200, 'body' => $body];
    $path = base_path('../../packages/php-sdk/fixtures/operations/operation-show/overdue.json');
    if (getenv('ORBIT_FIXTURES') === 'record') {
        file_put_contents($path, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }
    expect($fixture)->toBe(json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR));
});

it('does not backfill successful Operations with an overdue marker', function (): void {
    $this->freezeTime();
    $operation = overdueReportingOperation();
    $operation->update(['status' => OperationStatus::Succeeded, 'finished_at' => now()]);
    $this->travel(20)->minutes();

    expect(OperationData::summary(app(ExpireOperationAction::class)->execute($operation))->toArray()['overdue_since'])->toBeNull();
    expect(Activity::query()->where('command', 'operation:overdue-alert')->count())->toBe(0);
});
