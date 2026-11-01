<?php

declare(strict_types=1);

use App\Actions\Operations\StartOperationAction;
use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationContribution;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketException;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Infrastructure\Swamp\SwampRunSubscriber;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Psr\Log\NullLogger;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\FakeSwampEventSocket;

function startIntent(): Operation
{
    $node = Node::query()->create(['name' => 'native-start', 'public_ssh_host' => '192.0.2.9', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active']);

    return Operation::query()->create(['kind' => 'route.private_dns_publish', 'target_type' => 'node_route_projection',
        'target_id' => $node->id, 'node_id' => $node->id, 'status' => OperationStatus::Queued, 'sequence' => 1, 'input' => [], 'state' => []]);
}

it('submits once after admission commits and leaves confirmed native work running', function (): void {
    $this->freezeSecond();
    $node = Node::query()->create(['name' => 'committed-start', 'public_ssh_host' => '192.0.2.9', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active']);
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.start-token']);
    DB::beginTransaction();
    $operation = app(StartOperationAction::class)->execute('route.private_dns_publish', 'node_route_projection', $node->id, $node->id, [], null, null);
    expect($socket->sent)->toBe([]);
    expect($operation->fresh()->state)->toBe(['swamp_start' => [
        'key' => 'operation:'.$operation->id, 'workflow' => 'operation-route-publication',
    ]]);

    DB::commit();

    expect($operation->fresh())->status->toBe(OperationStatus::Running)->error_code->toBeNull()->finished_at->toBeNull();
    expect($operation->fresh()->started_at->equalTo(now()))->toBeTrue();
    expect($operation->fresh()->dispatched_at->equalTo(now()))->toBeTrue();
    expect($operation->fresh()->state['swamp_run_id'])->toBe('a075eb23-7ac4-4487-80d1-57a038a92676');
    expect($operation->fresh()->sequence)->toBe(3);
    expect(Schema::hasTable('operation_steps'))->toBeFalse();
    expect(array_column($socket->sent, 'type'))->toBe(['workflow.run']);
});

it('rolls back creation when the admission receipt cannot be stored', function (): void {
    $node = Node::query()->create(['name' => 'receipt-fixture', 'public_ssh_host' => '192.0.2.9', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active']);
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    $dispatcher = Operation::getEventDispatcher();
    Operation::setEventDispatcher(clone $dispatcher);
    Operation::saving(static function (Operation $operation): void {
        if (isset($operation->state['swamp_start'])) {
            throw new RuntimeException('Receipt write refused.');
        }
    });
    try {
        expect(fn () => app(StartOperationAction::class)->execute('route.private_dns_publish', 'node_route_projection', $node->id, $node->id, [], null, null))
            ->toThrow(RuntimeException::class, 'Receipt write refused.');
        expect(Operation::query()->where('node_id', $node->id)->exists())->toBeFalse();
        expect($socket->sent)->toBe([]);
    } finally {
        Operation::setEventDispatcher($dispatcher);
    }
});

it('preserves unrelated state when adding the admission receipt', function (): void {
    $node = Node::query()->create(['name' => 'receipt-fixture', 'public_ssh_host' => '192.0.2.9', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active']);
    $dispatcher = Operation::getEventDispatcher();
    Operation::setEventDispatcher(clone $dispatcher);
    Operation::creating(static function (Operation $operation): void {
        $operation->state = [...($operation->state ?? []), 'public_fixture' => 'retained'];
    });
    try {
        $operation = app(OperationStore::class)->create('route.private_dns_publish', 'node_route_projection', $node->id, $node->id, [], null, null);
        expect($operation->fresh()->state)->toBe([
            'public_fixture' => 'retained',
            'swamp_start' => ['key' => 'operation:'.$operation->id, 'workflow' => 'operation-route-publication'],
        ]);
        expect($operation->status)->toBe(OperationStatus::Queued);
    } finally {
        Operation::setEventDispatcher($dispatcher);
    }
});

it('leaves replayed and fenced admissions untouched without submitting', function (bool $fenced): void {
    $node = Node::query()->create(['name' => 'receipt-fixture', 'public_ssh_host' => '192.0.2.9', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active']);
    $identity = app(OperationIdempotency::class);
    $identity->kind = 'route.private_dns_publish';
    $identity->key = 'receipt-replay';
    $identity->fingerprint = str_repeat('a', 64);
    $operation = $fenced
        ? app(OperationStore::class)->fenceAdmission($identity->kind, $identity->key, $identity->fingerprint, 'node_route_projection', $node->id, $node->id, [], 'fixture', 'fixture')
        : Operation::query()->create(['kind' => $identity->kind, 'idempotency_key' => $identity->key, 'idempotency_fingerprint' => $identity->fingerprint,
            'target_type' => 'node_route_projection', 'target_id' => $node->id, 'node_id' => $node->id, 'input' => [],
            'state' => ['retained' => true], 'status' => OperationStatus::Queued, 'sequence' => 1]);
    $before = $operation->fresh()->getAttributes();
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);

    $replayed = app(StartOperationAction::class)->execute($identity->kind, 'node_route_projection', $node->id, $node->id, [], 'fixture', 'fixture');

    expect($replayed->id)->toBe($operation->id);
    expect($replayed->fresh()->getAttributes())->toBe($before);
    expect($socket->sent)->toBe([]);
})->with(['existing intent' => false, 'admission fence' => true]);

it('makes configuration failures terminal before any native submission', function (string $setting, mixed $value): void {
    $operation = startIntent();
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.start-token', $setting => $value]);

    try {
        app(StartSwampRun::class)->start($operation);
        $this->fail('Expected a terminal start failure.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('swamp.start_unavailable');
        expect($exception->details)->toBe(['operation_id' => $operation->id]);
        expect($exception->getMessage())->not->toContain('private.start-token');
    }

    expect($operation->fresh())->status->toBe(OperationStatus::Failed)->error_code->toBe('swamp.start_unavailable');
    expect($operation->fresh()->finished_at)->not->toBeNull();
    expect($socket->connections)->toBe([]);
    expect($socket->sent)->toBe([]);
})->with(['missing token' => ['swamp.run_token', ''], 'malformed token' => ['swamp.run_token', []], 'missing endpoint' => ['swamp.address', ''], 'malformed trust path' => ['swamp.ca_path', []]]);

it('makes a connect failure terminal without searching or sending native work', function (): void {
    $operation = startIntent();
    $socket = new FakeRoutePublicationSocket;
    $socket->onConnect = static function (): void {
        throw new WebSocketException('private diagnostic with token', 504);
    };
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.start-token']);

    expect(fn () => app(StartSwampRun::class)->start($operation))->toThrow(ResourceOperationException::class, 'could not connect');

    expect($operation->fresh())->status->toBe(OperationStatus::Failed)->error_code->toBe('swamp.start_unavailable');
    expect(json_encode($operation->fresh()->toArray()))->not->toContain('private diagnostic')->not->toContain('private.start-token');
    expect($socket->connections)->toBe([15.0]);
    expect($socket->sent)->toBe([]);
    expect($socket->closed)->toBeTrue();
});

it('records a native refusal as failed without its diagnostic', function (): void {
    $operation = startIntent();
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => [['type' => 'error', 'id' => $request['id'], 'message' => 'private remote diagnostic']];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.start-token']);

    expect(fn () => app(StartSwampRun::class)->start($operation))->toThrow(ResourceOperationException::class, 'Swamp refused');

    expect($operation->fresh())->status->toBe(OperationStatus::Failed)->error_code->toBe('swamp.start_refused');
    expect(json_encode($operation->fresh()->toArray()))->not->toContain('private remote diagnostic');
    expect(array_column($socket->sent, 'type'))->toBe(['workflow.run']);
});

it('logs refusal diagnostics after removing credentials and secret patterns', function (): void {
    $operation = startIntent();
    $socket = new FakeRoutePublicationSocket;
    $message = "Access denied: user:orbit-gateway needs run on workflow:operation-route-publication private.start-token password=hidden-password Bearer hidden-bearer-token\n-----BEGIN PRIVATE KEY-----\nhidden-pem\n-----END PRIVATE KEY-----";
    $socket->reply = static fn (array $request): array => [['type' => 'error', 'id' => $request['id'], 'error' => ['code' => 'unauthorized', 'message' => $message, 'details' => ['token' => 'hidden-detail']]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.start-token']);
    Log::spy();

    expect(fn () => app(StartSwampRun::class)->start($operation))->toThrow(ResourceOperationException::class, 'Swamp refused');

    Log::shouldHaveReceived('warning')->with('Swamp direct start refused', Mockery::on(static function (array $context): bool {
        expect($context['remote_code'])->toBe('unauthorized');
        expect($context['remote_type'])->toBe('error');
        expect($context['remote_message'])->toContain('Access denied', 'workflow:operation-route-publication', '[REDACTED]');
        expect(json_encode($context))->not->toContain('private.start-token', 'hidden-password', 'hidden-bearer-token', 'hidden-pem', 'hidden-detail');

        return true;
    }))->once();
    expect(json_encode($operation->fresh()->toArray()))->not->toContain('Access denied', 'hidden-password', 'hidden-pem');
});

it('withholds malformed diagnostics without losing a native refusal', function (mixed $diagnostic, string $expected): void {
    $operation = startIntent();
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => [['type' => 'error', 'id' => $request['id'], 'code' => 'workflow_execution_failed', 'message' => $diagnostic]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.start-token']);
    Log::spy();

    expect(fn () => app(StartSwampRun::class)->start($operation))->toThrow(ResourceOperationException::class, 'Swamp refused');

    Log::shouldHaveReceived('warning')->with('Swamp direct start refused', Mockery::on(static function (array $context) use ($expected): bool {
        expect($context['remote_code'])->toBe('workflow_execution_failed');
        expect($context['remote_message'])->toBe($expected);

        return true;
    }))->once();
    expect($operation->fresh()->error_code)->toBe('swamp.start_refused');
})->with(['flat error' => ['Workflow is not loaded', 'Workflow is not loaded'], 'not text' => [['token' => 'hidden'], '[withheld]'],
    'invalid UTF-8' => ["\xff", '[withheld]'], 'over limit' => [str_repeat('x', 32769), '[withheld]']]);

it('keeps a lost or malformed response active and never resubmits on replay', function (bool $malformed): void {
    $operation = startIntent();
    $socket = new FakeRoutePublicationSocket(false);
    if ($malformed) {
        $socket->reply = static fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-route-publication', 'runId' => 'not-a-native-uuid']]];
    }
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.start-token']);
    $operation->update(['idempotency_key' => 'same-intent', 'idempotency_fingerprint' => str_repeat('a', 64)]);
    $identity = app(OperationIdempotency::class);
    $identity->kind = $operation->kind;
    $identity->key = 'same-intent';
    $identity->fingerprint = str_repeat('a', 64);

    expect(fn () => app(StartSwampRun::class)->start($operation))->toThrow(ResourceOperationException::class, 'A native run may exist');
    $replayed = $identity->replay($operation->kind);
    app(StartSwampRun::class)->start($replayed);

    expect($replayed->id)->toBe($operation->id);
    expect($operation->fresh())->status->toBe(OperationStatus::Running)->error_code->toBe('swamp.start_uncertain');
    expect($operation->fresh()->finished_at)->toBeNull();
    expect($operation->fresh()->state)->not->toHaveKey('swamp_run_id');
    expect(array_column($socket->sent, 'type'))->toBe(['workflow.run']);
    expect($socket->connections)->toBe([15.0]);
})->with(['lost response' => false, 'malformed response' => true]);

it('binds a native root that fails before the first behavior and pins terminal conflict', function (string $kind): void {
    $operation = startIntent();
    $runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => $kind, 'workflowName' => 'operation-route-publication', 'runId' => $runId, 'message' => 'private native error']]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.start-token']);

    expect(fn () => app(StartSwampRun::class)->start($operation))->toThrow(ResourceOperationException::class, 'ended before its start completed');

    expect($operation->fresh())->status->toBe(OperationStatus::Failed)->error_code->toBe('operation.native_dispatch_failed');
    expect($operation->fresh()->state['swamp_run_id'])->toBe($runId);
    expect(json_encode($operation->fresh()->toArray()))->not->toContain('private native error');
    expect(fn () => app(DirectOperation::class)->project($operation, ['run_id' => $runId, 'status' => 'succeeded', 'started_at' => '2026-10-30T12:00:00Z', 'finished_at' => '2026-10-30T12:00:01Z'], []))
        ->toThrow(ResourceOperationException::class, 'another terminal result');
    app(StartSwampRun::class)->start($operation);
    expect(array_column($socket->sent, 'type'))->toBe(['workflow.run']);
})->with(['failed', 'cancelled']);

it('preserves an observed root binding when its response is lost', function (): void {
    $operation = startIntent();
    $runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static function () use ($operation, $runId): array {
        $operation->fresh()->update(['state' => ['swamp_run_id' => $runId]]);

        return [];
    };
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.start-token']);

    expect(fn () => app(StartSwampRun::class)->start($operation))->toThrow(ResourceOperationException::class);

    expect($operation->fresh())->status->toBe(OperationStatus::Running)->error_code->toBe('swamp.start_uncertain');
    expect($operation->fresh()->state['swamp_run_id'])->toBe($runId);
});

it('reserves running status before sending so a concurrent admission cannot submit twice', function (): void {
    $operation = startIntent();
    $socket = new FakeRoutePublicationSocket;
    $socket->onConnect = static function () use ($operation): void {
        expect($operation->fresh()->status)->toBe(OperationStatus::Running);
        app(StartSwampRun::class)->start($operation->fresh());
    };
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.start-token']);

    app(StartSwampRun::class)->start($operation);

    expect($operation->fresh()->status)->toBe(OperationStatus::Running);
    expect($socket->connections)->toBe([15.0]);
    expect(array_column($socket->sent, 'type'))->toBe(['workflow.run']);
});

final readonly class StartTerminalProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return ['intent' => ['name' => 'public-fixture'], 'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return app(DirectOperation::class)->project($operation, $data, $data['result']);
    }
}

function startTerminalIntent(): Operation
{
    app()->instance(DirectOperation::class, new DirectOperation([new class implements DirectOperationContribution
    {
        public function definitions(): array
        {
            return ['test.fast_terminal' => ['workflow' => 'operation-test-fast-terminal',
                'workflow_inputs' => true, 'projection' => StartTerminalProjection::class,
                'terminal_result' => ['job' => 'completion', 'step' => 'result']]];
        }
    }]));
    $operation = startIntent();
    $operation->update(['kind' => 'test.fast_terminal']);

    return $operation;
}

function startTerminalHistory(Operation $operation, string $status): array
{
    return ['id' => 'a075eb23-7ac4-4487-80d1-57a038a92676', 'workflowName' => 'operation-test-fast-terminal',
        'status' => $status, 'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => $operation->kind],
        'startedAt' => '2026-10-31T13:00:00Z', 'completedAt' => '2026-10-31T13:00:01Z',
        'jobs' => [['name' => 'completion', 'steps' => [['name' => 'result', 'outputs' => ['stdout' => '{"ready":true}']]]]]];
}

it('announces a fast completed binding and projects it through the observer without a queue consumer', function (string $status, string $eventKind): void {
    config(['queue.default' => 'gateway']);
    $operation = startTerminalIntent();
    $history = startTerminalHistory($operation, $status);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static function (array $request) use ($history, $eventKind): array {
        if ($request['type'] === 'workflow.history.get') {
            return [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => $history]]];
        }

        return [
            ['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => $history['workflowName'], 'runId' => $history['id']]],
            ['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => $eventKind, 'run' => $history]],
        ];
    };
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.start-token', 'swamp.read_token' => 'private.read-token']);

    app(StartSwampRun::class)->start($operation);

    expect($operation->fresh()->status)->toBe(OperationStatus::Running);
    expect(array_column($socket->sent, 'type'))->toBe(['workflow.run']);
    expect(DB::table('jobs')->count())->toBe(0);
    $native = new FakeSwampEventSocket;
    $subscriber = new SwampRunSubscriber($native, new NullLogger);
    $subscriber->pass();
    $native->push(['type' => 'error', 'id' => $native->sent[0]['id'], 'code' => 'not_found']);
    $subscriber->pass();

    expect($operation->fresh()->status->value)->toBe($status);
    expect($operation->fresh()->state['swamp_run_id'])->toBe($history['id']);
    expect($operation->fresh()->result)->toBe(['ready' => true]);
    expect(array_column($socket->sent, 'type'))->toBe(['workflow.run', 'workflow.history.get']);
    expect($socket->sent[1]['payload'])->toBe(['workflowIdOrName' => $history['id']]);
    expect($socket->closed)->toBeTrue();
})->with(['success' => ['succeeded', 'completed'], 'failure' => ['failed', 'completed'], 'cancellation' => ['cancelled', 'cancelled']]);

it('ignores a foreign terminal frame in the start batch without reading history', function (string $foreign): void {
    $operation = startTerminalIntent();
    $history = startTerminalHistory($operation, 'succeeded');
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static function (array $request) use ($history, $foreign): array {
        $terminal = ['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'completed', 'run' => $history]];
        match ($foreign) {
            'request' => $terminal['id'] = 'another-request',
            'run' => $terminal['event']['run']['id'] = 'd97886ab-d1c5-4efa-a737-c2db51208465',
            'workflow' => $terminal['event']['run']['workflowName'] = 'another-workflow',
            'child' => $terminal['event']['parentRunId'] = $history['id'],
            'active' => $terminal['event']['run']['status'] = 'running',
        };

        return [
            ['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => $history['workflowName'], 'runId' => $history['id']]],
            $terminal,
        ];
    };
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.start-token']);

    app(StartSwampRun::class)->start($operation);

    expect($operation->fresh()->status)->toBe(OperationStatus::Running);
    expect($operation->fresh()->state['swamp_run_id'])->toBe($history['id']);
    expect(array_column($socket->sent, 'type'))->toBe(['workflow.run']);
})->with(['request', 'run', 'workflow', 'child', 'active']);

it('keeps an accepted start bound when its coalesced terminal history is unavailable', function (): void {
    config(['queue.default' => 'gateway']);
    $operation = startTerminalIntent();
    $history = startTerminalHistory($operation, 'succeeded');
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static function (array $request) use ($history): array {
        if ($request['type'] === 'workflow.history.get') {
            return [['type' => 'error', 'id' => $request['id'], 'error' => ['code' => 'forbidden', 'message' => 'private-native-diagnostic']]];
        }

        return [
            ['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => $history['workflowName'], 'runId' => $history['id']]],
            ['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'completed', 'run' => $history]],
        ];
    };
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.start-token', 'swamp.read_token' => 'private.read-token']);

    app(StartSwampRun::class)->start($operation);
    app(StartSwampRun::class)->start($operation->fresh());

    $native = new FakeSwampEventSocket;
    $subscriber = new SwampRunSubscriber($native, new NullLogger);
    $subscriber->pass();
    $native->push(['type' => 'error', 'id' => $native->sent[0]['id'], 'code' => 'not_found']);
    $subscriber->pass();
    expect(DB::table('jobs')->count())->toBe(0);

    expect($operation->fresh()->status)->toBe(OperationStatus::Running);
    expect($operation->fresh()->error_code)->toBeNull();
    expect($operation->fresh()->state['swamp_run_id'])->toBe($history['id']);
    expect(array_column($socket->sent, 'type'))->toBe(['workflow.run', 'workflow.history.get']);
    expect(json_encode($operation->fresh()->toArray()))->not->toContain('private-native-diagnostic');
});

it('announces the committed native binding on the existing private orbit record channel', function (): void {
    $operation = startTerminalIntent();
    Event::fake([RecordBroadcast::class]);
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.start-token']);

    app(StartSwampRun::class)->start($operation);

    Event::assertDispatched(RecordBroadcast::class, fn (RecordBroadcast $event): bool => $event->channel === 'orbit'
        && $event->id === $operation->id && ($event->data['sequence'] ?? null) === 3 && ($event->data['status'] ?? null) === 'running');
    expect($operation->fresh()->state['swamp_run_id'])->toBe('a075eb23-7ac4-4487-80d1-57a038a92676');
});

it('announces a fast bound run without dispatching terminal projection during admission', function (): void {
    $operation = startTerminalIntent();
    Event::fake([RecordBroadcast::class]);
    Bus::fake();
    $history = startTerminalHistory($operation, 'succeeded');
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => [
        ['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => $history['workflowName'], 'runId' => $history['id']]],
        ['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'completed', 'run' => $history]],
    ];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.start-token']);

    app(StartSwampRun::class)->start($operation);

    Bus::assertNothingDispatched();
    expect($operation->fresh()->status)->toBe(OperationStatus::Running);
    expect($operation->fresh()->state['swamp_run_id'])->toBe($history['id']);
    expect($operation->fresh()->error_code)->toBeNull();
    expect(array_column($socket->sent, 'type'))->toBe(['workflow.run']);
    Event::assertDispatched(RecordBroadcast::class, fn (RecordBroadcast $event): bool => $event->channel === 'orbit'
        && $event->id === $operation->id && ($event->data['sequence'] ?? null) === 3);
});

it('accepts a root after measured pre-dispatch setup delays without waiting for worker completion', function (float $delay): void {
    $operation = startIntent();
    $socket = new FakeRoutePublicationSocket;
    $socket->keepConnected = true;
    $time = 0.0;
    $received = 0;
    $socket->reply = static function (array $request) use (&$time, &$received, $delay): array {
        if (++$received === 1) {
            $time += $delay;

            return [];
        }

        return [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started',
            'workflowName' => 'operation-route-publication', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    };
    config(['swamp.run_token' => 'private.start-token']);
    $clock = static function () use (&$time): float {
        return $time;
    };

    (new StartSwampRun($socket, $clock))->start($operation);

    expect($operation->fresh())->status->toBe(OperationStatus::Running)->error_code->toBeNull()->finished_at->toBeNull();
    expect($operation->fresh()->state['swamp_run_id'])->toBe('a075eb23-7ac4-4487-80d1-57a038a92676');
    expect(array_column($socket->sent, 'type'))->toBe(['workflow.run']);
    expect($received)->toBe(2)->and($socket->closed)->toBeTrue();
})->with([32.7, 55.3]);

it('retains the target hold when the bounded confirmation wait expires', function (): void {
    $operation = startIntent();
    $operation->update(['lock_key' => 'native-start-confirmation-fixture']);
    $socket = new FakeRoutePublicationSocket;
    $socket->keepConnected = true;
    $time = 0.0;
    $socket->reply = static function () use (&$time): array {
        $time = 121.0;

        return [];
    };
    config(['swamp.run_token' => 'private.start-token']);
    $clock = static function () use (&$time): float {
        return $time;
    };

    expect(fn () => (new StartSwampRun($socket, $clock))->start($operation))->toThrow(ResourceOperationException::class, 'A native run may exist');

    expect($operation->fresh())->status->toBe(OperationStatus::Running)->error_code->toBe('swamp.start_uncertain')->finished_at->toBeNull();
    expect($operation->fresh()->lock_key)->toBe('native-start-confirmation-fixture');
    expect($operation->fresh()->state)->not->toHaveKey('swamp_run_id');
    expect(array_column($socket->sent, 'type'))->toBe(['workflow.run']);
    expect($socket->closed)->toBeTrue();
});
