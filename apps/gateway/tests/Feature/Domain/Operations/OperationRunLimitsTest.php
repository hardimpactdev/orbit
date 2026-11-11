<?php

declare(strict_types=1);

use App\Actions\Operations\ExpireOperationAction;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Domain\Releases\ReleaseAlertStep;
use App\Domain\Releases\ReleaseAlertWebhook;
use App\Infrastructure\Swamp\NativeRunProgress;
use App\Infrastructure\Swamp\SwampReadBudget;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;

uses(LazilyRefreshDatabase::class);

function runLimitOperation(int $soft = 360): Operation
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit']));
    $node->roles()->create(['role' => 'gateway', 'status' => 'active']);

    return Operation::query()->create(['kind' => 'tasks.script', 'target_type' => 'task_group', 'target_id' => 42, 'node_id' => $node->id,
        'input' => ['run_budget_seconds' => $soft], 'status' => OperationStatus::Running, 'attempts' => 1,
        'lock_key' => 'task_group:42', 'started_at' => now(),
        'state' => ['swamp_run_id' => 'ea55ab37-bcbe-4459-81dc-cbb397e7d870']]);
}

it('marks a run past its soft limit overdue without cancelling and alerts once', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-11T10:00:00Z'));
    Queue::fake();
    $operation = runLimitOperation();
    $this->travel(7)->minutes();

    $current = app(ExpireOperationAction::class)->execute($operation);

    expect($current->status)->toBe(OperationStatus::Running)
        ->and($current->cancel_requested_at)->toBeNull()
        ->and($current->state['native_cancel_requested_at'] ?? null)->toBeNull()
        ->and($current->state['overdue_since'] ?? null)->toBe('2026-11-11T10:06:00.000000Z');
    expect(Activity::query()->where('command', 'operation:overdue-alert')->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('cancels a run whose last step transition is older than its hard limit', function (int $soft, int $idle): void {
    $this->freezeTime();
    Queue::fake();
    $operation = runLimitOperation($soft);
    app(NativeRunProgress::class)->apply($operation->id, $operation->state['swamp_run_id'],
        ['kind' => 'step_started', 'stepId' => 'work', 'jobId' => 'main', 'seq' => 1, 'startedAt' => now()->toISOString()]);
    $this->travel($idle - 2)->seconds();
    expect(app(ExpireOperationAction::class)->execute($operation->refresh())->status)->toBe(OperationStatus::Running);
    $this->travel(2)->seconds();

    $current = app(ExpireOperationAction::class)->execute($operation->refresh());

    expect($current->status)->toBe(OperationStatus::Failed)
        ->and($current->error_code)->toBe('operation.timed_out')
        ->and($current->cancel_requested_at)->not->toBeNull()
        ->and($current->state)->toHaveKey('native_cancel_requested_at')
        ->and($current->failed_step)->toBe('work')
        ->and(OperationRunBudgets::deadline($current->refresh())?->toISOString())->toBe($current->state['native_timeout']['deadline']);
})->with(['soft plus thirty minutes' => [360, 2161], 'three times soft' => [1200, 3601]]);

it('keeps a run past three times soft alive while steps keep transitioning', function (): void {
    $this->freezeTime();
    Queue::fake();
    $operation = runLimitOperation(1200);
    foreach (range(1, 4) as $seq) {
        $this->travel(19)->minutes();
        app(NativeRunProgress::class)->apply($operation->id, $operation->state['swamp_run_id'],
            ['kind' => 'step_started', 'stepId' => 'work-'.$seq, 'jobId' => 'main', 'seq' => $seq, 'startedAt' => now()->toISOString()]);
        app(ExpireOperationAction::class)->execute($operation->refresh());
    }

    expect($operation->refresh()->status)->toBe(OperationStatus::Running)
        ->and($operation->cancel_requested_at)->toBeNull()
        ->and($operation->current_step)->toBe('work-4');
    expect(Activity::query()->where('command', 'operation:overdue-alert')->count())->toBe(1);
});

it('does not repeat the overdue alert on later passes or reads', function (): void {
    $this->freezeTime();
    Queue::fake();
    $webhook = Mockery::mock(ReleaseAlertWebhook::class);
    $webhook->shouldReceive('send')->once()->withArgs(fn (array $payload): bool => $payload['event'] === 'operation.overdue'
        && $payload['soft_limit_seconds'] === 360 && ! isset($payload['input']))->andReturn(ReleaseAlertStep::failed('unavailable'));
    app()->instance(ReleaseAlertWebhook::class, $webhook);
    $operation = runLimitOperation();
    $this->travel(7)->minutes();
    app(ExpireOperationAction::class)->execute($operation);
    $sequence = $operation->refresh()->sequence;
    $this->travel(1)->minutes();

    app(ExpireOperationAction::class)->execute($operation->refresh());
    app(ExpireOperationAction::class)->execute($operation->refresh());

    expect(Activity::query()->where('command', 'operation:overdue-alert')->count())->toBe(1);
    expect($operation->refresh()->sequence)->toBe($sequence);
    expect(Activity::query()->where('command', 'operation:overdue-alert')->sole()->error_code)->toBe('operation.alert_webhook_failed');
});

it('uses started_at for a hard limit when no step was observed', function (): void {
    $this->freezeTime();
    Queue::fake();
    $operation = runLimitOperation();
    $this->travel(35)->minutes();
    expect(app(ExpireOperationAction::class)->execute($operation)->status)->toBe(OperationStatus::Running);
    $this->travel(2)->minutes();

    expect(app(ExpireOperationAction::class)->execute($operation->refresh())->error_code)->toBe('operation.timed_out');
});

it('records the overdue API response without requesting cancellation', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-11T10:00:00Z'));
    Queue::fake();
    $operation = runLimitOperation();
    $operation->update(['input' => ['run_budget_seconds' => 900, 'soft_limit_seconds' => 360]]);
    $this->travel(7)->minutes();
    app(SwampReadBudget::class)->take('operation:'.$operation->id, 30);

    $response = $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2'])->getJson('/api/v1/operations/'.$operation->id)
        ->assertOk()->assertJsonPath('data.status', 'running')->assertJsonPath('data.cancel_requested', false)
        ->assertJsonPath('data.overdue_since', '2026-11-11T10:06:00.000000Z');

    $body = $response->json();
    $body['meta']['request_id'] = '0198e15c-bf97-7c23-8f1f-61b8fe67a846';
    $fixture = ['schema' => 1, 'request' => ShowOperationRequest::class, 'route' => 'GET /api/v1/operations/{operation}', 'status' => 200, 'body' => $body];
    $path = base_path('../../packages/php-sdk/fixtures/operations/operation-show/overdue.json');
    if (getenv('ORBIT_FIXTURES') === 'record') {
        file_put_contents($path, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }
    expect($fixture)->toBe(json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR));
});

it('keeps the latest transition after a step ends with an empty or overlapping active list', function (string $kind, bool $overlap): void {
    $this->travelTo(new DateTimeImmutable('2026-11-11T10:00:00Z'));
    Queue::fake();
    $operation = runLimitOperation();
    $run = $operation->state['swamp_run_id'];
    $progress = app(NativeRunProgress::class);
    if ($overlap) {
        $progress->apply($operation->id, $run, ['kind' => 'step_started', 'stepId' => 'long', 'jobId' => 'parallel', 'seq' => 1,
            'startedAt' => '2026-11-11T10:01:00Z']);
    }
    $progress->apply($operation->id, $run, ['kind' => 'step_started', 'stepId' => 'work', 'jobId' => 'main', 'seq' => 2,
        'startedAt' => '2026-11-11T10:20:00Z']);
    $this->travel(35)->minutes();

    $progress->apply($operation->id, $run, ['kind' => $kind, 'stepId' => 'work', 'jobId' => 'main', 'seq' => 3,
        'completedAt' => '2026-11-11T10:35:00Z', 'allowedFailure' => true]);
    $current = app(ExpireOperationAction::class)->execute($operation->refresh());

    expect(OperationRunBudgets::deadline($current)?->toISOString())->toBe('2026-11-11T11:11:00.000000Z')
        ->and($current->status)->toBe(OperationStatus::Running)
        ->and($current->current_step)->toBe($overlap ? 'long' : null);
})->with(['completion' => 'step_completed', 'failure' => 'step_failed', 'skip' => 'step_skipped'])
    ->with(['empty' => false, 'overlap' => true]);

it('does not give a delayed step start a fresh hard allowance', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-11T10:00:00Z'));
    Queue::fake();
    $operation = runLimitOperation();
    $this->travel(60)->minutes();

    app(NativeRunProgress::class)->apply($operation->id, $operation->state['swamp_run_id'],
        ['kind' => 'step_started', 'stepId' => 'work', 'jobId' => 'main', 'seq' => 1, 'startedAt' => '2026-11-11T10:01:00Z']);
    $current = app(ExpireOperationAction::class)->execute($operation->refresh());

    expect(OperationRunBudgets::deadline($current)?->toISOString())->toBe('2026-11-11T10:37:00.000000Z')
        ->and($current->error_code)->toBe('operation.timed_out');
});

it('preserves a step transition across replay and repeated same-step observations', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-11T10:00:00Z'));
    $operation = runLimitOperation();
    $progress = app(NativeRunProgress::class);
    $event = ['kind' => 'step_started', 'stepId' => 'work', 'jobId' => 'main', 'seq' => 1, 'startedAt' => '2026-11-11T10:01:00Z'];
    $this->travel(5)->minutes();
    $progress->apply($operation->id, $operation->state['swamp_run_id'], $event);
    $this->travel(25)->minutes();

    $progress->apply($operation->id, $operation->state['swamp_run_id'], [...$event, 'seq' => 2, 'startedAt' => '2026-11-11T10:25:00Z']);
    $progress->apply($operation->id, $operation->state['swamp_run_id'], $event);

    expect(OperationRunBudgets::deadline($operation->refresh())?->toISOString())->toBe('2026-11-11T10:37:00.000000Z');
});

it('anchors delayed step endings to their native time', function (string $kind): void {
    $this->travelTo(new DateTimeImmutable('2026-11-11T10:00:00Z'));
    Queue::fake();
    $operation = runLimitOperation();
    $this->travel(80)->minutes();

    app(NativeRunProgress::class)->apply($operation->id, $operation->state['swamp_run_id'],
        ['kind' => $kind, 'stepId' => 'work', 'jobId' => 'main', 'seq' => 1, 'completedAt' => '2026-11-11T10:35:00Z', 'allowedFailure' => true]);
    $current = app(ExpireOperationAction::class)->execute($operation->refresh());

    expect(OperationRunBudgets::deadline($current)?->toISOString())->toBe('2026-11-11T11:11:00.000000Z')
        ->and($current->error_code)->toBe('operation.timed_out');
})->with(['completion' => 'step_completed', 'failure' => 'step_failed', 'skip' => 'step_skipped']);

it('does not move the hard deadline backwards for an older transition on another step', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-11T10:00:00Z'));
    $operation = runLimitOperation();
    $progress = app(NativeRunProgress::class);
    $this->travel(30)->minutes();
    $progress->apply($operation->id, $operation->state['swamp_run_id'],
        ['kind' => 'step_started', 'stepId' => 'recent', 'jobId' => 'main', 'seq' => 1, 'startedAt' => '2026-11-11T10:20:00Z']);

    $progress->apply($operation->id, $operation->state['swamp_run_id'],
        ['kind' => 'step_started', 'stepId' => 'old', 'jobId' => 'parallel', 'seq' => 2, 'startedAt' => '2026-11-11T10:05:00Z']);

    expect(OperationRunBudgets::deadline($operation->refresh())?->toISOString())->toBe('2026-11-11T10:56:00.000000Z');
});

it('does not replace the start anchor with receipt time when native transition time is missing', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-11T10:00:00Z'));
    Queue::fake();
    $operation = runLimitOperation();
    $this->travel(60)->minutes();

    app(NativeRunProgress::class)->apply($operation->id, $operation->state['swamp_run_id'],
        ['kind' => 'step_started', 'stepId' => 'work', 'jobId' => 'main', 'seq' => 1]);

    expect(app(ExpireOperationAction::class)->execute($operation->refresh())->error_code)->toBe('operation.timed_out');
});
