<?php

declare(strict_types=1);

use App\Actions\Operations\ExpireOperationAction;
use App\Actions\Operations\ReadOperationAction;
use App\Data\Operations\OperationData;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampRunSubscriber;
use App\Jobs\Operations\ProjectSwampTerminal;
use App\Jobs\Operations\RecoverUnboundSwampRun;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Psr\Log\NullLogger;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\FakeSwampEventSocket;

function budgetOperation(string $kind = 'instance.create', array $attributes = []): Operation
{
    $node = Node::query()->create(['name' => 'budget-target', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9']);

    return Operation::query()->create([
        'kind' => $kind, 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
        'status' => OperationStatus::Running, 'sequence' => 1, 'started_at' => now(),
        'input' => ['run_budget_seconds' => 60], 'state' => ['swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676'],
        'lock_key' => 'budget:'.$node->id, 'current_step' => 'source', ...$attributes,
    ]);
}

beforeEach(function (): void {
    $this->freezeSecond();
    Http::preventStrayRequests();
});

it('expires create at its captured budget while retaining F1 evidence and requesting operator cancellation', function (): void {
    $operation = budgetOperation(attributes: ['state' => ['swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676',
        'native_failure' => ['failed_step' => 'source', 'detail' => 'Safe native diagnostic.', 'stderr_recorded' => true],
        'retained' => true], 'result' => ['public_observation' => 'retained']]);
    $deadline = OperationRunBudgets::deadline($operation);
    $this->travel(59)->seconds();
    app(ExpireOperationAction::class)->execute($operation);
    expect($operation->fresh()->status)->toBe(OperationStatus::Running);
    expect($operation->fresh()->cancel_requested_at)->toBeNull();
    $this->travel(1)->seconds();

    $expired = app(ExpireOperationAction::class)->execute($operation);
    $public = OperationData::fromModel($expired)->toArray();

    expect($expired->status)->toBe(OperationStatus::Failed);
    expect($expired->error_code)->toBe('operation.timed_out');
    expect($expired->cancel_requested_at?->equalTo(now()))->toBeTrue();
    expect($expired->failed_step)->toBe('source');
    expect($expired->current_step)->toBeNull();
    expect($expired->lock_key)->toBe($operation->lock_key);
    expect($expired->state['swamp_run_id'])->toBe('a075eb23-7ac4-4487-80d1-57a038a92676');
    expect($expired->state['retained'])->toBeTrue();
    expect($expired->state['native_timeout'])->toBe(['budget_seconds' => 60, 'deadline' => $deadline->toISOString()]);
    expect($expired->result)->toBe(['public_observation' => 'retained', 'error_code' => 'operation.timed_out']);
    expect($public['error'])->toBe(['code' => 'operation.timed_out', 'detail' => 'Safe native diagnostic.']);
    $sequence = $expired->sequence;
    app(ExpireOperationAction::class)->execute($expired);
    expect($expired->fresh()->sequence)->toBe($sequence);
    Http::assertNothingSent();
});

it('gives a bound kind with no successful history thirty minutes instead of three hundred seconds', function (): void {
    $operation = budgetOperation('instance.rename', ['input' => []]);
    expect(OperationRunBudgets::seconds($operation))->toBe(1800);
    $this->travel(1799)->seconds();
    app(ExpireOperationAction::class)->execute($operation);
    expect($operation->fresh()->status)->toBe(OperationStatus::Running);
    $this->travel(1)->seconds();

    expect(app(ExpireOperationAction::class)->execute($operation)->error_code)->toBe('operation.timed_out');
});

it('uses one and a half times the longest successful bound run in fourteen days rounded up to a minute', function (): void {
    $operation = budgetOperation('instance.rename', ['input' => []]);
    foreach ([[601, 1, 'succeeded', true], [300, 2, 'succeeded', true], [3600, 15, 'succeeded', true],
        [3600, 1, 'failed', true], [3600, 1, 'succeeded', false]] as [$duration, $days, $status, $bound]) {
        $history = $operation->replicate();
        $history->forceFill(['status' => $status, 'lock_key' => null,
            'state' => $bound ? $operation->state : [], 'started_at' => now()->subDays($days)->subSeconds($duration),
            'finished_at' => now()->subDays($days)])->save();
    }
    expect(OperationRunBudgets::seconds($operation))->toBe(960);
    $this->travel(959)->seconds();
    app(ExpireOperationAction::class)->execute($operation);
    expect($operation->fresh()->status)->toBe(OperationStatus::Running);
    $this->travel(1)->seconds();

    expect(app(ExpireOperationAction::class)->execute($operation)->error_code)->toBe('operation.timed_out');
});

it('caches a history budget for one observer tick and refreshes it in the next tick', function (): void {
    $operation = budgetOperation('instance.rename', ['input' => []]);
    OperationRunBudgets::duringTick(function () use ($operation): void {
        expect(OperationRunBudgets::seconds($operation))->toBe(1800);
        $history = $operation->replicate();
        $history->forceFill(['status' => 'succeeded', 'lock_key' => null,
            'started_at' => now()->subSeconds(100), 'finished_at' => now()])->save();
        expect(OperationRunBudgets::seconds($operation))->toBe(1800);
    });

    OperationRunBudgets::duringTick(fn () => expect(OperationRunBudgets::seconds($operation))->toBe(180));
});

it('gives every unlisted registered kind a thirty minute initial execution budget', function (): void {
    $explicit = array_keys((new ReflectionClassConstant(OperationRunBudgets::class, 'SecondsByKind'))->getValue());
    $unlisted = array_diff(array_keys(app(DirectOperation::class)->definitions()), $explicit);
    expect($unlisted)->not->toBeEmpty();
    OperationRunBudgets::duringTick(function () use ($unlisted): void {
        foreach ($unlisted as $kind) {
            $operation = new Operation(['kind' => $kind, 'input' => [], 'started_at' => now(),
                'state' => ['swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]);
            expect(OperationRunBudgets::seconds($operation))->toBe(1800);
        }
    });
});

it('expires a no run admission at six hundred seconds regardless of its execution budget', function (string $kind): void {
    $operation = budgetOperation($kind, ['attempts' => 0, 'state' => [], 'input' => [], 'started_at' => null]);
    $this->travel(599)->seconds();
    app(ExpireOperationAction::class)->execute($operation);
    expect($operation->fresh()->status)->toBe(OperationStatus::Running);
    $this->travel(1)->seconds();

    $expired = app(ExpireOperationAction::class)->execute($operation);

    expect($expired->error_code)->toBe('operation.timed_out');
    expect($expired->state['native_timeout']['budget_seconds'])->toBe(600);
    expect($expired->lock_key)->not->toBeNull();
})->with(['instance.rename', 'instance.create', 'tasks.provision']);

it('leaves Gateway-only jobs outside the native run deadline', function (string $kind, bool $started): void {
    $operation = budgetOperation($kind, ['status' => $started ? OperationStatus::Running : OperationStatus::Queued,
        'started_at' => $started ? now() : null]);
    $this->travel(600)->seconds();
    app(ExpireOperationAction::class)->execute($operation);

    expect($operation->fresh()->sequence)->toBe(1);
    expect($operation->fresh()->cancel_requested_at)->toBeNull();
})->with([['test.gateway_only', true]]);

it('preserves a winning native terminal result even when expiry has a stale Operation object', function (string $status): void {
    $operation = budgetOperation();
    $this->travel(60)->seconds();
    Operation::query()->whereKey($operation->id)->update(['status' => $status, 'result' => ['native' => 'retained']]);

    $result = app(ExpireOperationAction::class)->execute($operation);

    expect($result->status->value)->toBe($status);
    expect($result->result)->toBe(['native' => 'retained']);
    expect($result->sequence)->toBe(1);
    expect($result->cancel_requested_at)->toBeNull();
})->with(['succeeded', 'failed', 'cancelled', 'interrupted']);

it('applies overdue expiry before a native read even when history and the observer are unavailable', function (): void {
    $operation = budgetOperation();
    $this->travel(60)->seconds();
    $socket = new FakeSwampEventSocket;
    $socket->onConnect = static function (): void {
        throw new RuntimeException('No history read is allowed.');
    };
    app()->instance(WebSocketClient::class, $socket);

    $result = app(ReadOperationAction::class)->execute($operation);

    expect($result->error_code)->toBe('operation.timed_out');
    expect($socket->sent)->toBeEmpty();
    expect($socket->endpoints)->toBeEmpty();
});

it('expires tracked roots through the existing observer while the native socket is disconnected', function (): void {
    $operation = budgetOperation();
    config(['swamp.run_token' => null]);
    $socket = new FakeSwampEventSocket;
    $subscriber = new SwampRunSubscriber($socket, new NullLogger, static fn (): float => now()->getTimestamp());
    config(['queue.connections.gateway.driver' => 'sync']);
    $subscriber->pass();
    $this->travel(60)->seconds();

    $subscriber->pass();

    expect($operation->fresh()->error_code)->toBe('operation.timed_out');
    expect($operation->fresh()->cancel_requested_at)->not->toBeNull();
    expect($socket->sent)->toBeEmpty();
});

it('recovers overdue unbound and waiting roots without waiting for history or overwriting a prior cancel', function (): void {
    $requested = now()->toImmutable();
    $operation = budgetOperation(attributes: ['status' => OperationStatus::Waiting, 'attempts' => 0, 'state' => [], 'cancel_requested_at' => $requested]);
    $this->travel(600)->seconds();
    $socket = new FakeSwampEventSocket;

    (new SwampRunSubscriber($socket, new NullLogger))->recover();

    expect($operation->fresh()->error_code)->toBe('operation.timed_out');
    expect($operation->fresh()->cancel_requested_at?->equalTo($requested))->toBeTrue();
    expect($socket->sent)->toBeEmpty();
});

it('uses a captured heavy create phase plan instead of the superseded fixed sixty seconds', function (): void {
    $operation = budgetOperation(attributes: ['input' => ['run_budget_seconds' => 180,
        'phase_budgets' => ['source' => 30, 'environment' => 30, 'setup' => 120]]]);
    $this->travel(179)->seconds();
    app(ExpireOperationAction::class)->execute($operation);
    expect($operation->fresh()->status)->toBe(OperationStatus::Running);
    $this->travel(1)->seconds();
    $expired = app(ExpireOperationAction::class)->execute($operation);
    expect($expired->state['native_timeout']['budget_seconds'])->toBe(180);
    expect($expired->error_code)->toBe('operation.timed_out');
});

it('uses sixty seconds for a create without a captured phase plan', function (): void {
    $operation = budgetOperation(attributes: ['input' => []]);
    expect(OperationRunBudgets::seconds($operation))->toBe(60);
});

it('retains target exclusion until exact native terminal history releases it without reopening timeout', function (string $status): void {
    $operation = budgetOperation('node.bootstrap_probe');
    $this->travel(1800)->seconds();
    $expired = app(ExpireOperationAction::class)->execute($operation);
    $competing = ['kind' => 'instance.rename', 'target_type' => 'node', 'target_id' => $operation->target_id,
        'node_id' => $operation->node_id, 'status' => 'queued', 'sequence' => 1, 'input' => [], 'state' => [], 'lock_key' => $operation->lock_key];
    expect(fn () => Operation::query()->create($competing))->toThrow(UniqueConstraintViolationException::class);
    $history = new FakeRoutePublicationSocket;
    $history->reply = static fn (array $request): array => [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => [
        'id' => $operation->state['swamp_run_id'], 'workflowName' => 'operation-node-bootstrap-probe', 'status' => $status,
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => $operation->kind],
    ]]]];
    app()->instance(WebSocketClient::class, $history);
    config(['swamp.read_token' => 'fixture.read', 'swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/public/ca.pem']);

    $settled = app(SwampCommandStep::class)->projectTerminal($expired,
        ['id' => $operation->state['swamp_run_id']], ['job' => 'bootstrap', 'step' => 'probe-host']);

    expect($settled->lock_key)->toBeNull();
    expect($settled->status)->toBe(OperationStatus::Failed);
    expect($settled->error_code)->toBe('operation.timed_out');
    expect($settled->state['native_timeout']['native_terminal_status'])->toBe($status);
    expect($settled->result)->toBe($expired->result);
    expect(Operation::query()->create($competing)->id)->not->toBe($operation->id);
})->with(['succeeded', 'failed', 'cancelled', 'interrupted']);

it('keeps budget reception live while an uncertain-start history lookup waits on the operations queue', function (): void {
    Bus::fake([RecoverUnboundSwampRun::class]);
    $operation = budgetOperation('node.bootstrap_probe', ['attempts' => 0, 'state' => [], 'error_code' => 'swamp.start_uncertain']);
    $operation->update(['state' => ['swamp_start' => ['key' => 'operation:'.$operation->id, 'workflow' => 'operation-node-bootstrap-probe']]]);
    $history = new FakeSwampEventSocket;
    $history->onConnect = static function (): void {
        throw new RuntimeException('The observer must not read history.');
    };
    app()->instance(WebSocketClient::class, $history);
    $subscriber = new SwampRunSubscriber(new FakeSwampEventSocket, new NullLogger, static fn (): float => now()->getTimestamp());
    $subscriber->pass();
    Bus::assertDispatched(RecoverUnboundSwampRun::class,
        fn ($job): bool => $job->operationId === $operation->id && $job->queue === 'operations');
    $this->travel(600)->seconds();

    $subscriber->pass();

    expect($operation->fresh()->error_code)->toBe('operation.timed_out');
    expect($operation->fresh()->lock_key)->toBe($operation->lock_key);
    expect($history->sent)->toBeEmpty();
});

it('keeps the timed out root attached until a verified native terminal receipt releases its hold', function (): void {
    config(['queue.connections.gateway.driver' => 'sync', 'swamp.run_token' => 'fixture.run',
        'swamp.read_token' => 'fixture.read', 'swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/public/ca.pem']);
    $operation = budgetOperation('node.bootstrap_probe');
    $events = new FakeSwampEventSocket;
    $history = new FakeRoutePublicationSocket;
    $nativeStatus = 'running';
    $history->reply = static function (array $request) use ($operation, &$nativeStatus): array {
        return [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => [
            'id' => $operation->state['swamp_run_id'], 'workflowName' => 'operation-node-bootstrap-probe', 'status' => $nativeStatus,
            'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => $operation->kind],
        ]]]];
    };
    app()->instance(WebSocketClient::class, $history);
    $subscriber = new SwampRunSubscriber($events, new NullLogger, static fn (): float => now()->getTimestamp());
    $subscriber->pass();
    $request = $events->sent[0];
    $events->push(['type' => 'run.attached', 'id' => $request['id'], 'payload' => ['runId' => $operation->state['swamp_run_id'], 'kind' => 'workflow-run']]);
    $subscriber->pass();
    $this->travel(1800)->seconds();
    $subscriber->pass();
    expect($operation->fresh()->lock_key)->not->toBeNull();
    $nativeStatus = 'cancelled';
    $events->push(['type' => 'event', 'id' => $request['id'], 'event' => ['seq' => 5, 'kind' => 'completed', 'run' => [
        'id' => $operation->state['swamp_run_id'], 'workflowName' => 'operation-node-bootstrap-probe', 'status' => 'cancelled',
    ]]]);

    $subscriber->pass();

    expect($operation->fresh()->error_code)->toBe('operation.timed_out');
    expect($operation->fresh()->lock_key)->toBeNull();
    expect($operation->fresh()->state['native_timeout']['native_terminal_status'])->toBe('cancelled');
    expect($history->sent)->toHaveCount(2);
});

it('expires never-started native admissions on the first observer startup with no run or attempts', function (string $kind, string $status, bool $started, bool $requested): void {
    $cancelled = $requested ? now()->subSeconds(500)->toImmutable() : null;
    $operation = budgetOperation($kind, ['status' => $status, 'attempts' => 0, 'started_at' => $started ? now()->subSeconds(600) : null,
        'cancel_requested_at' => $cancelled, 'state' => ['retained' => true], 'input' => [],
        'error_code' => $kind === 'doctor.inspect' ? 'swamp.start_uncertain' : null]);
    $operation->forceFill(['created_at' => now()->subSeconds(600)])->save();
    $socket = new FakeSwampEventSocket;
    $subscriber = new SwampRunSubscriber($socket, new NullLogger);

    $subscriber->pass();

    $expired = $operation->fresh();
    expect($expired->status)->toBe(OperationStatus::Failed);
    expect($expired->error_code)->toBe('operation.timed_out');
    expect($expired->attempts)->toBe(0);
    expect($expired->state)->not->toHaveKey('swamp_run_id');
    expect($expired->state['retained'])->toBeTrue();
    expect($expired->cancel_requested_at?->equalTo($cancelled ?? now()))->toBeTrue();
    expect($socket->sent)->toBeEmpty();
    expect($socket->endpoints)->toBeEmpty();
})->with([
    ['vault.sync', 'running', true, true],
    ['doctor.inspect', 'running', true, false],
    ['doctor.inspect', 'queued', false, true],
    ['vault.sync', 'queued', false, false],
]);

it('keeps a long native kind running past the default budget until its measured budget', function (): void {
    $operation = budgetOperation('task_vm.image_build', ['input' => []]);
    $this->travel(301)->seconds();
    app(ExpireOperationAction::class)->execute($operation);
    expect($operation->fresh()->status)->toBe(OperationStatus::Running);
    $this->travel(2400 - 301)->seconds();

    expect(app(ExpireOperationAction::class)->execute($operation)->error_code)->toBe('operation.timed_out');
});

it('reads the measured budget of each long native kind', function (string $kind, int $seconds): void {
    expect(OperationRunBudgets::seconds(budgetOperation($kind, ['input' => []])))->toBe($seconds);
})->with([
    ['node.converge', 3900],
    ['node.role_add', 2700], ['task_vm.destroy', 2700], ['task_vm.image_build', 2400], ['task_vm.sweep_host', 600],
    ['tasks.provision', 3900], ['cluster.router_set', 3900], ['cluster.router_unset', 3900],
]);

it('budgets only registered direct-operation kinds', function (): void {
    $kinds = array_keys((new ReflectionClassConstant(OperationRunBudgets::class, 'SecondsByKind'))->getValue());

    expect(array_diff($kinds, array_keys(app(DirectOperation::class)->definitions())))->toBe([]);
});

it('lets an on time native terminal win a late projection in one history read', function (string $status, int $seconds): void {
    config(['swamp.read_token' => 'fixture.read', 'swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/public/ca.pem']);
    $operation = budgetOperation('node.bootstrap_probe');
    $started = now()->toISOString();
    $completed = now()->addSeconds($seconds)->toISOString();
    $history = new FakeRoutePublicationSocket;
    $history->reply = static fn (array $request): array => [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => [
        'id' => $operation->state['swamp_run_id'], 'workflowName' => 'operation-node-bootstrap-probe', 'status' => $status,
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => $operation->kind],
        'startedAt' => $started, 'completedAt' => $completed,
        'jobs' => [['name' => 'bootstrap', 'steps' => [['name' => 'probe-host', 'outputs' => ['exitCode' => 0,
            'stdout' => '{"probed":true,"architecture":"x86_64","public_key":"public-fixture"}']]]]],
    ]]]];
    app()->instance(WebSocketClient::class, $history);
    $this->travel(1805)->seconds();

    $result = app(ReadOperationAction::class)->execute($operation);

    expect($result->status->value)->toBe($seconds <= 1800 ? $status : 'failed');
    expect($result->error_code)->toBe($seconds <= 1800 ? null : 'operation.timed_out');
    expect($result->cancel_requested_at === null)->toBe($seconds <= 1800);
    expect($result->lock_key)->toBeNull();
    expect($history->sent)->toHaveCount(1);
})->with([['succeeded', 1799], ['succeeded', 1800], ['succeeded', 1801]]);

it('settles a timed out unbound hold only after the exact native lookup proves absence', function (string $outcome): void {
    config(['swamp.read_token' => 'fixture.read', 'swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/public/ca.pem']);
    $operation = budgetOperation('node.bootstrap_probe', ['state' => [], 'attempts' => 0]);
    $run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    if (in_array($outcome, ['stale workflow', 'stale key'], true)) {
        $operation->update(['state' => ['swamp_start' => ['key' => $outcome === 'stale key' ? 'operation:another' : 'operation:'.$operation->id,
            'workflow' => $outcome === 'stale workflow' ? 'operation-old-workflow' : 'operation-node-bootstrap-probe']]]);
    }
    $this->travel(600)->seconds();
    app(ExpireOperationAction::class)->execute($operation);
    expect($operation->fresh()->lock_key)->not->toBeNull();
    $candidate = ['runId' => $run, 'workflowName' => 'operation-node-bootstrap-probe',
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => $operation->kind],
        'tags' => ['orbit_start_key' => 'operation:'.$operation->id]];
    if ($outcome === 'mismatch') {
        $candidate['inputs']['operation_id']++;
    }
    $history = new FakeRoutePublicationSocket;
    $history->reply = static fn (array $request): array => [['type' => $request['type'], 'id' => $request['id'],
        'payload' => ['total' => $outcome === 'absent' ? 0 : ($outcome === 'ambiguous' ? 2 : 1),
            'data' => ['results' => $outcome === 'absent' ? [] : [$candidate]]]]];
    app()->instance(WebSocketClient::class, $history);

    if (in_array($outcome, ['mismatch', 'ambiguous'], true)) {
        expect(fn () => (new RecoverUnboundSwampRun($operation->id))->handle(app(SwampCommandStep::class)))
            ->toThrow(ResourceOperationException::class);
    } else {
        (new RecoverUnboundSwampRun($operation->id))->handle(app(SwampCommandStep::class));
    }

    $result = $operation->fresh();
    expect($result->error_code)->toBe('operation.timed_out');
    expect($result->lock_key === null)->toBe($outcome === 'absent');
    expect($result->state['swamp_run_id'] ?? null)->toBe($outcome === 'matching' ? $run : null);
    if (in_array($outcome, ['stale workflow', 'stale key'], true)) {
        expect($history->sent)->toBeEmpty();

        return;
    }
    expect($history->sent)->toHaveCount(1);
    expect($history->sent[0]['payload'])->not->toHaveKey('workflow');
    expect($history->sent[0]['type'])->toBe('workflow.run.search');
    expect($history->sent[0]['payload']['inputs']['operation_id'])->toBe((string) $operation->id);
})->with(['absent', 'matching', 'mismatch', 'ambiguous', 'stale workflow', 'stale key']);

it('projects queued or simultaneous terminal notices once after the deadline without a false timeout', function (bool $beforeDeadline, string $kind): void {
    Bus::fake([ProjectSwampTerminal::class]);
    config(['swamp.run_token' => 'fixture.run', 'swamp.read_token' => 'fixture.read',
        'swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/public/ca.pem']);
    config(['orbit.releases.alert_webhook_url' => 'https://hooks.example.test/budget-alert',
        'orbit.releases.alert_webhook_secret' => 'fixture-signing-key']);
    Http::fake(['https://hooks.example.test/budget-alert' => Http::response('', 200)]);
    $operation = budgetOperation($kind);
    if ($kind === 'instance.create') {
        $project = Project::query()->create(['name' => 'Budget source', 'slug' => 'budget-source', 'type' => 'node-package',
            'repository_url' => 'https://github.com/example/budget-source.git', 'root' => '.', 'default_branch' => 'main']);
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $operation->node_id,
            'name' => 'budget-proof', 'checkout_path' => '/srv/apps/budget-source/proof', 'source_layout' => 'checkout', 'status' => 'active']);
        $operation->update(['target_type' => 'instance', 'target_id' => $instance->id,
            'input' => ['instance_id' => $instance->id, 'run_budget_seconds' => 60, 'direct_provisioning' => ['already_active' => true]]]);
    }
    $definition = app(DirectOperation::class)->definition($kind);
    $budget = OperationRunBudgets::seconds($operation);
    expect($budget)->toBe($kind === 'instance.create' ? 60 : 1800);
    $start = now()->toISOString();
    $finish = now()->addSeconds($budget - 1)->toISOString();
    $events = new FakeSwampEventSocket;
    $subscriber = new SwampRunSubscriber($events, new NullLogger, static fn (): float => now()->getTimestamp());
    $subscriber->pass();
    $request = $events->sent[0];
    $events->push(['type' => 'run.attached', 'id' => $request['id'], 'payload' => [
        'runId' => $operation->state['swamp_run_id'], 'kind' => 'workflow-run']]);
    $subscriber->pass();
    $this->travel($beforeDeadline ? $budget - 1 : $budget + 4)->seconds();
    $events->push(['type' => 'event', 'id' => $request['id'], 'event' => ['seq' => 5, 'kind' => 'completed', 'run' => [
        'id' => $operation->state['swamp_run_id'], 'workflowName' => $definition['workflow'], 'status' => 'succeeded']]]);
    $subscriber->pass();
    $this->travel($beforeDeadline ? 5 : 0)->seconds();
    $subscriber->pass();
    expect($operation->fresh()->status)->toBe(OperationStatus::Running);
    expect($operation->fresh()->cancel_requested_at)->toBeNull();
    Bus::assertDispatchedTimes(ProjectSwampTerminal::class, 1);
    $history = new FakeRoutePublicationSocket;
    $history->reply = static fn (array $message): array => [['type' => 'workflow.history.get', 'id' => $message['id'], 'payload' => ['data' => [
        'id' => $operation->state['swamp_run_id'], 'workflowName' => $definition['workflow'], 'status' => 'succeeded',
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => $operation->kind],
        'startedAt' => $start, 'completedAt' => $finish,
        'jobs' => [['name' => $definition['terminal_result']['job'], 'steps' => [['name' => $definition['terminal_result']['step'], 'outputs' => ['exitCode' => 0,
            'stdout' => json_encode($kind === 'instance.create' ? ['ready' => true, 'instance_id' => $operation->input['instance_id'], 'already_active' => true]
                : ['probed' => true, 'architecture' => 'x86_64', 'public_key' => 'public-fixture'], JSON_THROW_ON_ERROR)]]]]],
    ]]]];
    app()->instance(WebSocketClient::class, $history);

    (new ProjectSwampTerminal($operation->id, $operation->state['swamp_run_id'], 'succeeded'))->handle(app(SwampCommandStep::class));

    expect($operation->fresh()->status)->toBe(OperationStatus::Succeeded);
    expect($operation->fresh()->error_code)->toBeNull();
    expect($operation->fresh()->cancel_requested_at)->toBeNull();
    expect($history->sent)->toHaveCount(1);
    Http::assertNothingSent();
    expect(Activity::query()->where('command', 'instance:failure-alert')->exists())->toBeFalse();
})->with([[true, 'node.bootstrap_probe'], [false, 'node.bootstrap_probe'], [true, 'instance.create']]);

it('uses own history or thirty minutes for kinds without an explicit measured budget', function (string $kind): void {
    $operation = budgetOperation($kind, ['input' => []]);
    expect(OperationRunBudgets::seconds($operation))->toBe(1800);
    $history = $operation->replicate();
    $history->forceFill(['status' => 'succeeded', 'lock_key' => null,
        'started_at' => now()->subSeconds(600), 'finished_at' => now()])->save();

    expect(OperationRunBudgets::seconds($operation))->toBe(900);
})->with(['node.role_converge', 'node.role_relocate']);
