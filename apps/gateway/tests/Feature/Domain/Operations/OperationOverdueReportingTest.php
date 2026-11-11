<?php

declare(strict_types=1);

use App\Actions\Operations\ExpireOperationAction;
use App\Data\Operations\OperationData;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Domain\Releases\ReleaseAlertStep;
use App\Domain\Releases\ReleaseAlertWebhook;
use App\Jobs\Operations\ReconcileSwampOperations;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;

uses(LazilyRefreshDatabase::class);

function overdueReportingOperation(bool $bound = true): Operation
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->firstOrCreate(['name' => 'gateway'], ['status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit']));
    $node->roles()->firstOrCreate(['role' => 'gateway'], ['status' => 'active']);

    return Operation::query()->create(['kind' => 'tasks.script', 'target_type' => 'task_group', 'target_id' => 42, 'node_id' => $node->id,
        'input' => ['run_budget_seconds' => 900], 'status' => OperationStatus::Running, 'attempts' => $bound ? 1 : 0,
        'sequence' => 1, 'lock_key' => 'task_group:42', 'started_at' => now(), 'current_step' => 'work',
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
    expect(Activity::query()->where('command', 'operation:overdue-alert')->sole()->status)->toBe('succeeded');
    Queue::assertPushed(ReconcileSwampOperations::class, 1);
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

    app(ExpireOperationAction::class)->execute($operation);
    app(ExpireOperationAction::class)->execute($operation->refresh());

    expect(Activity::query()->where('command', 'operation:overdue-alert')->sole()->error_code)->toBe('operation.alert_webhook_failed');
    expect($operation->refresh()->sequence)->toBe(3);
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
