<?php

declare(strict_types=1);

use App\Actions\Operations\ExpireOperationAction;
use App\Actions\Operations\ReadOperationAction;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Domain\Releases\ReleaseAlertStep;
use App\Domain\Releases\ReleaseAlertWebhook;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\NativeRunProgress;
use App\Infrastructure\Swamp\SwampConnection;
use App\Infrastructure\Swamp\SwampReadBudget;
use App\Jobs\Operations\ReconcileSwampOperations;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Tests\Support\NativeRunBudgetSocket;

uses(LazilyRefreshDatabase::class);

afterEach(fn () => app(SwampConnection::class)->release());

function runLimitOperation(int $soft = 360): Operation
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit']));
    $node->roles()->create(['role' => 'gateway', 'status' => 'active']);

    $operation = Operation::query()->create(['kind' => 'tasks.script', 'target_type' => 'task_group', 'target_id' => 42, 'node_id' => $node->id,
        'input' => ['run_budget_seconds' => $soft], 'status' => OperationStatus::Running, 'attempts' => 1,
        'lock_key' => 'task_group:42', 'started_at' => now(),
        'state' => ['swamp_run_id' => 'ea55ab37-bcbe-4459-81dc-cbb397e7d870']]);
    NativeRunBudgetSocket::install($operation);

    return $operation;
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
        ['kind' => 'step_started', 'stepId' => 'work', 'jobId' => 'main', 'seq' => 1]);
    app(WebSocketClient::class)->jobs = [['name' => 'main', 'steps' => [['name' => 'work', 'status' => 'running', 'startedAt' => now()->toISOString()]]]];
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
            ['kind' => 'step_started', 'stepId' => 'work-'.$seq, 'jobId' => 'main', 'seq' => $seq]);
        app(WebSocketClient::class)->jobs = [['name' => 'main', 'steps' => [['name' => 'work-'.$seq, 'status' => 'running', 'startedAt' => now()->toISOString()]]]];
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

/** @return array<string,mixed> Native-produced payloads: see the fixture provenance and db3f6373 source. */
function nativeBudgetPayload(string $case): array
{
    return json_decode(file_get_contents(base_path('tests/Fixtures/SwampRunBudgets/db3f6373.json')), true, flags: JSON_THROW_ON_ERROR)['cases'][$case];
}

it('initializes the hard anchor from pinned running history before expiry, including Operations already running at deploy', function (string $entry): void {
    $this->travelTo(new DateTimeImmutable('2026-11-11T10:00:00Z'));
    Queue::fake();
    $operation = runLimitOperation();
    $socket = NativeRunBudgetSocket::install($operation);
    $payload = nativeBudgetPayload('running');
    $socket->jobs = $payload['history']['payload']['data']['jobs'];
    $this->travel(40)->minutes();
    if ($entry !== 'already running') {
        app(NativeRunProgress::class)->apply($operation->id, $operation->state['swamp_run_id'], $payload['event']['event']);
    } else {
        $operation->update(['state' => [...$operation->state, 'native_progress' => ['run' => $operation->state['swamp_run_id'], 'seq' => 1,
            'active' => ['old' => ['step' => 'work', 'started_at' => null, 'observed_at' => '2026-11-11T10:10:00.000000Z']], 'steps' => []]]]);
    }
    expect($payload['event']['event'])->not->toHaveKeys(['startedAt', 'completedAt']);

    match ($entry) {
        'operation read' => app(ReadOperationAction::class)->execute($operation->refresh()),
        'reconciliation' => app()->call([new ReconcileSwampOperations, 'handle']),
        default => app(ExpireOperationAction::class)->execute($operation->refresh()),
    };

    expect($operation->refresh()->status)->toBe(OperationStatus::Running)
        ->and(OperationRunBudgets::deadline($operation)?->toISOString())->toBe('2026-11-11T11:16:00.000000Z')
        ->and($operation->cancel_requested_at)->toBeNull();
    expect(array_column($socket->frames, 'type'))->toContain('workflow.history.get')->not->toContain('workflow.cancel');
})->with(['expiry action', 'operation read', 'reconciliation', 'already running']);

it('retains completion, failure and skip anchors with empty or overlapping active steps', function (string $case, bool $overlap): void {
    $this->travelTo(new DateTimeImmutable('2026-11-11T10:00:00Z'));
    Queue::fake();
    $operation = runLimitOperation();
    $socket = NativeRunBudgetSocket::install($operation);
    $payload = nativeBudgetPayload($case);
    $socket->jobs = $payload['history']['payload']['data']['jobs'];
    if ($overlap) {
        app(NativeRunProgress::class)->apply($operation->id, $operation->state['swamp_run_id'],
            ['kind' => 'step_started', 'jobId' => 'parallel', 'stepId' => 'long', 'seq' => 1]);
        $socket->jobs[] = ['name' => 'parallel', 'status' => 'running', 'steps' => [['name' => 'long', 'status' => 'running', 'startedAt' => '2026-11-11T10:01:00Z']]];
    }
    $this->travel(45)->minutes();
    app(NativeRunProgress::class)->apply($operation->id, $operation->state['swamp_run_id'], [...$payload['event']['event'], 'seq' => 2]);

    $current = app(ExpireOperationAction::class)->execute($operation->refresh());

    expect(OperationRunBudgets::deadline($current)?->toISOString())->toBe('2026-11-11T11:11:00.000000Z')
        ->and($current->status)->toBe(OperationStatus::Running)
        ->and($current->current_step)->toBe($overlap ? 'long' : null);
})->with(['succeeded', 'failed', 'skipped'])->with(['empty' => false, 'overlap' => true]);

it('does not renew stuck work from delayed events or repeated history observations', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-11T10:00:00Z'));
    Queue::fake();
    $operation = runLimitOperation();
    $socket = NativeRunBudgetSocket::install($operation);
    $payload = nativeBudgetPayload('running');
    $socket->jobs = $payload['history']['payload']['data']['jobs'];
    $this->travel(40)->minutes();
    app(ExpireOperationAction::class)->execute($operation);
    $this->travel(40)->minutes();
    app(NativeRunProgress::class)->apply($operation->id, $operation->state['swamp_run_id'], $payload['event']['event']);
    app(NativeRunProgress::class)->apply($operation->id, $operation->state['swamp_run_id'], [...$payload['event']['event'], 'seq' => 2]);
    app(NativeRunProgress::class)->apply($operation->id, $operation->state['swamp_run_id'], $payload['event']['event']);

    $current = app(ExpireOperationAction::class)->execute($operation->refresh());

    expect(OperationRunBudgets::deadline($current)?->toISOString())->toBe('2026-11-11T11:16:00.000000Z')
        ->and($current->error_code)->toBe('operation.timed_out');
});

it('uses native end time rather than delayed receipt time for completion, failure and skip', function (string $case): void {
    $this->travelTo(new DateTimeImmutable('2026-11-11T10:00:00Z'));
    Queue::fake();
    $operation = runLimitOperation();
    $socket = NativeRunBudgetSocket::install($operation);
    $payload = nativeBudgetPayload($case);
    $socket->jobs = $payload['history']['payload']['data']['jobs'];
    $this->travel(80)->minutes();
    app(NativeRunProgress::class)->apply($operation->id, $operation->state['swamp_run_id'], $payload['event']['event']);

    $current = app(ExpireOperationAction::class)->execute($operation->refresh());

    expect(OperationRunBudgets::deadline($current)?->toISOString())->toBe('2026-11-11T11:11:00.000000Z')
        ->and($current->error_code)->toBe('operation.timed_out');
})->with(['succeeded', 'failed', 'skipped']);

it('checks authoritative descendant progress before declaring a parent stuck', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-11T10:00:00Z'));
    Queue::fake();
    $operation = runLimitOperation();
    $socket = NativeRunBudgetSocket::install($operation);
    $child = nativeBudgetPayload('running')['history']['payload']['data'];
    $child['id'] = '986bc349-54dc-4dea-9f22-76e3353d1cb1';
    $child['parentRun'] = ['workflowId' => $child['workflowId'], 'workflowName' => $child['workflowName'], 'runId' => $operation->state['swamp_run_id'], 'stepName' => 'child'];
    $socket->children[$child['id']] = $child;
    $this->travel(40)->minutes();

    $current = app(ExpireOperationAction::class)->execute($operation);

    expect($current->status)->toBe(OperationStatus::Running)
        ->and(OperationRunBudgets::deadline($current)?->toISOString())->toBe('2026-11-11T11:16:00.000000Z');
});

it('does not cancel from an unverified deadline when native history is unavailable', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-11T10:00:00Z'));
    Queue::fake();
    $operation = runLimitOperation();
    $socket = NativeRunBudgetSocket::install($operation);
    $socket->unavailable = true;
    $this->travel(80)->minutes();

    $current = app(ExpireOperationAction::class)->execute($operation);

    expect($current->status)->toBe(OperationStatus::Running)
        ->and($current->cancel_requested_at)->toBeNull();
});

it('defers hard expiry when the descendant listing is incomplete', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-11T10:00:00Z'));
    Queue::fake();
    $operation = runLimitOperation();
    $socket = NativeRunBudgetSocket::install($operation);
    $socket->incompleteSearch = true;
    $this->travel(80)->minutes();

    $current = app(ExpireOperationAction::class)->execute($operation);

    expect($current->status)->toBe(OperationStatus::Running)
        ->and($current->cancel_requested_at)->toBeNull();
});
