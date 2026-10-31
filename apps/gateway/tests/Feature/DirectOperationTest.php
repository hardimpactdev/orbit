<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketException;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $node = Node::query()->create(['name' => 'private-dev', 'public_ssh_host' => '192.0.2.9', 'user' => 'orbit', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    $this->operation = Operation::query()->create(['kind' => 'route.caddy_converge', 'target_type' => 'node_route_projection',
        'target_id' => $node->id, 'node_id' => $node->id, 'status' => OperationStatus::Queued, 'sequence' => 1,
        'state' => ['route_publication_writer' => 'direct'], 'input' => []]);
});

it('uses only the fixed workflow and shared token rather than Operation input', function (): void {
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.scoped.fixture']);
    $this->operation->update(['state' => ['route_publication_writer' => 'direct'], 'input' => ['workflow' => 'foreign-workflow', 'token_config' => 'foreign', 'node_id' => 100]]);
    app(StartSwampRun::class)->start($this->operation);

    expect($socket->endpoint->bearerToken)->toBe('private.scoped.fixture');
    expect($socket->sent[0]['payload'])->toBe(['workflowIdOrName' => 'operation-route-publication',
        'inputs' => ['operation_id' => $this->operation->id, 'kind' => 'route.caddy_converge', 'node_id' => $this->operation->node_id],
        'runtimeTags' => ['orbit_start_key' => 'operation:'.$this->operation->id]]);
    expect($socket->connections)->toBe([15.0]);
    expect(json_encode($this->operation->fresh()->toArray()))->not->toContain('private.scoped.fixture');
    expect($this->operation->fresh()->state)->not->toHaveKeys(['executor_runs', 'executor_lease_node_id']);
    expect($this->operation->fresh()->state['swamp_run_id'])->toBe('a075eb23-7ac4-4487-80d1-57a038a92676');
});

it('refuses an unbound worker run and never lets projection establish its identity', function (): void {
    $this->operation->update(['state' => ['route_publication_writer' => 'direct']]);
    $direct = app(DirectOperation::class);
    $runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    expect(fn () => $direct->assertRun($this->operation, $runId))->toThrow(ResourceOperationException::class);
    expect(fn () => $direct->project($this->operation, ['run_id' => $runId, 'status' => 'running',
        'started_at' => '2026-10-30T12:00:00.000Z'], [], 'publish'))->toThrow(ResourceOperationException::class);
    expect($this->operation->fresh()->state)->toBe(['route_publication_writer' => 'direct']);
    expect($this->operation->steps()->count())->toBe(0);
});

it('rejects undeclared and previous-writer intents before opening a native connection', function (): void {
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.scoped.fixture']);
    foreach ([['kind' => 'unknown.intent'], ['kind' => 'route.caddy_converge', 'state' => []]] as $changes) {
        $this->operation->update($changes);
        expect(fn () => app(StartSwampRun::class)->start($this->operation))->toThrow(ResourceOperationException::class);
    }
    expect($socket->sent)->toBe([]);
    expect($socket->endpoint)->toBeNull();
    expect(app(DirectOperation::class)->writer('unknown.intent'))->toBeNull();
});

it('fails closed without a configured scoped credential before sending intent', function (): void {
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => '']);
    expect(fn () => app(StartSwampRun::class)->start($this->operation))->toThrow(ResourceOperationException::class);
    expect($socket->sent)->toBe([]);
    expect($this->operation->fresh()->state)->toBe(['route_publication_writer' => 'direct']);
});

it('records only the matching root run and closes its single submission connection', function (): void {
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static function (array $request): array {
        $event = ['kind' => 'started', 'workflowName' => 'operation-route-publication', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676'];

        return [
            ['type' => 'event', 'id' => 'foreign', 'event' => $event],
            ['type' => 'event', 'id' => $request['id'], 'event' => [...$event, 'parentRunId' => 'parent']],
            ['type' => 'event', 'id' => $request['id'], 'event' => [...$event, 'workflowName' => 'foreign']],
            ['type' => 'event', 'id' => $request['id'], 'event' => $event],
        ];
    };
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.scoped.fixture']);
    app(StartSwampRun::class)->start($this->operation);
    expect($this->operation->fresh()->state['swamp_run_id'])->toBe('a075eb23-7ac4-4487-80d1-57a038a92676');
    expect($socket->sent)->toHaveCount(1);
    expect($socket->closed)->toBeTrue();
});

it('keeps a native refusal bounded without persisting the remote diagnostic', function (): void {
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => [['type' => 'error', 'id' => $request['id'], 'message' => 'private.scoped.fixture']];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.scoped.fixture']);
    try {
        app(StartSwampRun::class)->start($this->operation);
        $this->fail('Expected native refusal');
    } catch (ResourceOperationException $exception) {
        expect($exception->getMessage())->not->toContain('private.scoped.fixture');
    }
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Queued);
    expect($socket->sent)->toHaveCount(1);
    expect($socket->closed)->toBeTrue();
});

it('returns an already bound intent without another native submission', function (): void {
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.scoped.fixture']);
    $this->operation->update(['state' => ['route_publication_writer' => 'direct', 'swamp_run_id' => 'fabd4059-fb6c-49d8-940e-01ae58aa3f38']]);
    app(StartSwampRun::class)->start($this->operation);
    expect($this->operation->fresh()->state['swamp_run_id'])->toBe('fabd4059-fb6c-49d8-940e-01ae58aa3f38');
    expect($socket->sent)->toBe([]);
    expect($socket->endpoint)->toBeNull();
});

it('projects terminal results idempotently and rejects a changed run or outcome', function (): void {
    $this->operation->update(['state' => [...$this->operation->state, 'swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]);
    $direct = app(DirectOperation::class);
    $data = ['run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676', 'status' => 'succeeded',
        'started_at' => '2026-10-30T12:00:00Z', 'finished_at' => '2026-10-30T12:00:01Z'];
    $direct->project($this->operation, $data, ['ready' => true], 'publish');
    $sequence = $this->operation->sequence;
    $direct->project($this->operation, $data, ['ready' => true], 'publish');
    expect($this->operation->sequence)->toBe($sequence);
    expect($this->operation->attempts)->toBe(0);
    expect($this->operation->worker)->toBeNull();
    expect(fn () => $direct->project($this->operation, $data, ['ready' => false], 'publish'))->toThrow(ResourceOperationException::class);
    expect(fn () => $direct->project($this->operation, [...$data, 'run_id' => 'fabd4059-fb6c-49d8-940e-01ae58aa3f38'], ['ready' => true], 'publish'))->toThrow(ResourceOperationException::class);
});

it('recovers a lost start response from exact native history without resubmission', function (): void {
    $socket = new FakeRoutePublicationSocket;
    $operation = $this->operation;
    $socket->reply = static function (array $request) use ($operation): array {
        if ($request['type'] === 'workflow.run') {
            return [];
        }

        expect($request['type'])->toBe('workflow.run.search');
        expect($request['payload'])->toBe(['workflowIdOrName' => 'operation-route-publication',
            'inputs' => ['operation_id' => (string) $operation->id],
            'tags' => ['orbit_start_key' => 'operation:'.$operation->id], 'limit' => 2]);

        return [['type' => 'workflow.run.search', 'id' => $request['id'], 'payload' => ['total' => 1, 'data' => ['results' => [[
            'workflowName' => 'operation-route-publication', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676', 'status' => 'running',
            'tags' => ['orbit_start_key' => 'operation:'.$operation->id],
            'inputs' => ['operation_id' => $operation->id, 'kind' => $operation->kind, 'node_id' => $operation->node_id],
        ]]]]]];
    };
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.scoped.fixture']);

    app(StartSwampRun::class)->start($operation);

    expect($operation->fresh()->state['swamp_run_id'])->toBe('a075eb23-7ac4-4487-80d1-57a038a92676');
    expect(array_column($socket->sent, 'type'))->toBe(['workflow.run', 'workflow.run.search']);
    expect($socket->closed)->toBeTrue();
});

it('resubmits once with identical inputs and key only after confirmed empty native history', function (bool $loseSecondResponse): void {
    $socket = new FakeRoutePublicationSocket;
    $submissions = 0;
    $socket->reply = static function (array $request) use (&$submissions, $loseSecondResponse): array {
        if ($request['type'] === 'workflow.run.search') {
            return [['type' => 'workflow.run.search', 'id' => $request['id'], 'payload' => ['total' => 0, 'data' => ['results' => []]]]];
        }
        $submissions++;
        if ($submissions === 1 || $loseSecondResponse) {
            return [];
        }

        return [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started',
            'workflowName' => 'operation-route-publication', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    };
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.scoped.fixture']);

    if ($loseSecondResponse) {
        expect(fn () => app(StartSwampRun::class)->start($this->operation))
            ->toThrow(ResourceOperationException::class, 'Swamp did not confirm');
        expect($this->operation->fresh()->state)->not->toHaveKey('swamp_run_id');
    } else {
        app(StartSwampRun::class)->start($this->operation);
        expect($this->operation->fresh()->state['swamp_run_id'])->toBe('a075eb23-7ac4-4487-80d1-57a038a92676');
    }

    expect(array_column($socket->sent, 'type'))->toBe(['workflow.run', 'workflow.run.search', 'workflow.run']);
    expect($socket->sent[0]['payload'])->toBe($socket->sent[2]['payload']);
    expect($socket->closed)->toBeTrue();
})->with([false, true]);

it('keeps history failures and ambiguous or mismatched native runs uncertain without resubmitting', function (string $mode): void {
    $socket = new FakeRoutePublicationSocket;
    $operation = $this->operation;
    $socket->reply = static function (array $request) use ($mode, $operation): array {
        if ($request['type'] === 'workflow.run') {
            return [];
        }
        $run = ['workflowName' => 'operation-route-publication', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676', 'status' => 'running',
            'tags' => ['orbit_start_key' => 'operation:'.$operation->id],
            'inputs' => ['operation_id' => $operation->id, 'kind' => $operation->kind, 'node_id' => $operation->node_id]];
        $results = match ($mode) {
            'ambiguous' => [$run, [...$run, 'runId' => 'fabd4059-fb6c-49d8-940e-01ae58aa3f38']],
            'wrong-workflow' => [[...$run, 'workflowName' => 'foreign']],
            'wrong-key' => [[...$run, 'tags' => ['orbit_start_key' => 'operation:99999']]],
            'wrong-input' => [[...$run, 'inputs' => [...$run['inputs'], 'node_id' => 99999]]],
            'missing-run' => [[...$run, 'runId' => null]],
            'failed-run' => [[...$run, 'status' => 'failed']],
            'cancelled-run' => [[...$run, 'status' => 'cancelled']],
            'malformed-tags' => [[...$run, 'tags' => 'invalid']],
            'typed-input' => [[...$run, 'inputs' => [...$run['inputs'], 'operation_id' => (string) $operation->id]]],
            default => null,
        };

        return match ($mode) {
            'lost-history' => [],
            'refused-history' => [['type' => 'error', 'id' => $request['id'], 'error' => ['message' => 'private.scoped.fixture']]],
            default => [['type' => 'workflow.run.search', 'id' => $request['id'], 'payload' => ['total' => is_array($results) ? count($results) : null, 'data' => ['results' => $results]]]],
        };
    };
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.scoped.fixture']);

    expect(fn () => app(StartSwampRun::class)->start($operation))->toThrow(ResourceOperationException::class, 'Swamp did not confirm');

    expect(array_column($socket->sent, 'type'))->toBe(['workflow.run', 'workflow.run.search']);
    expect($operation->fresh()->state)->not->toHaveKey('swamp_run_id');
    expect($socket->closed)->toBeTrue();
})->with(['lost-history', 'refused-history', 'malformed', 'ambiguous', 'wrong-workflow', 'wrong-key', 'wrong-input', 'missing-run', 'failed-run', 'cancelled-run', 'malformed-tags', 'typed-input']);

it('recovers a connection failure through native history before submitting', function (): void {
    $socket = new FakeRoutePublicationSocket;
    $socket->onConnect = static function (int $connection): void {
        if ($connection === 1) {
            throw new WebSocketException('private.scoped.fixture', 504);
        }
    };
    $socket->reply = static fn (array $request): array => $request['type'] === 'workflow.run.search'
        ? [['type' => 'workflow.run.search', 'id' => $request['id'], 'payload' => ['total' => 0, 'data' => ['results' => []]]]]
        : [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started',
            'workflowName' => 'operation-route-publication', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.scoped.fixture']);

    app(StartSwampRun::class)->start($this->operation);

    expect(array_column($socket->sent, 'type'))->toBe(['workflow.run.search', 'workflow.run']);
    expect($socket->connections)->toBe([15.0, 15.0, 15.0]);
    expect($this->operation->fresh()->state['swamp_run_id'])->toBe('a075eb23-7ac4-4487-80d1-57a038a92676');
});

it('recovers an existing unbound replay after commit with one submission only after confirmed empty history', function (): void {
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => $request['type'] === 'workflow.run.search'
        ? [['type' => 'workflow.run.search', 'id' => $request['id'], 'payload' => ['total' => 0, 'data' => ['results' => []]]]]
        : [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started',
            'workflowName' => 'operation-route-publication', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.scoped.fixture']);

    $this->operation->update(['idempotency_key' => 'same-intent', 'idempotency_fingerprint' => str_repeat('a', 64)]);
    $identity = app(OperationIdempotency::class);
    $identity->kind = $this->operation->kind;
    $identity->key = 'same-intent';
    $identity->fingerprint = str_repeat('a', 64);
    DB::beginTransaction();
    $replayed = $identity->replay($this->operation->kind);
    expect($replayed->id)->toBe($this->operation->id);
    expect($socket->sent)->toBe([]);
    DB::commit();

    expect(array_column($socket->sent, 'type'))->toBe(['workflow.run.search', 'workflow.run']);
    expect($socket->sent[1]['payload'])->toBe(['workflowIdOrName' => 'operation-route-publication',
        'inputs' => ['operation_id' => $this->operation->id, 'kind' => $this->operation->kind, 'node_id' => $this->operation->node_id],
        'runtimeTags' => ['orbit_start_key' => 'operation:'.$this->operation->id]]);
    expect($this->operation->fresh()->state['swamp_run_id'])->toBe('a075eb23-7ac4-4487-80d1-57a038a92676');
});

it('does not resubmit an existing intent when its history remains unavailable', function (): void {
    $socket = new FakeRoutePublicationSocket(false);
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.scoped.fixture']);

    expect(fn () => app(StartSwampRun::class)->recover($this->operation))->toThrow(ResourceOperationException::class, 'Swamp did not confirm');

    expect(array_column($socket->sent, 'type'))->toBe(['workflow.run.search']);
    expect($this->operation->fresh()->state)->not->toHaveKey('swamp_run_id');
});

it('leaves bound terminal running and cancellation-requested intents alone during recovery', function (array $changes): void {
    $this->operation->update($changes);
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.scoped.fixture']);

    app(StartSwampRun::class)->recover($this->operation);

    expect($socket->connections)->toBe([]);
})->with([
    'bound' => [['state' => ['route_publication_writer' => 'direct', 'swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]],
    'succeeded' => [['status' => OperationStatus::Succeeded]],
    'failed' => [['status' => OperationStatus::Failed]],
    'cancelled' => [['status' => OperationStatus::Cancelled]],
    'running' => [['status' => OperationStatus::Running]],
    'cancellation requested' => [fn (): array => ['cancel_requested_at' => now()]],
]);

it('commits the direct start tag with admission before any submission and rolls both back together', function (): void {
    config(['swamp.direct_writers' => ['route.caddy_converge' => 'direct']]);
    DB::beginTransaction();
    $op = app(OperationStore::class)->create('route.caddy_converge', 'node_route_projection', $this->operation->node_id, $this->operation->node_id, [], 'test', null);
    expect($op->fresh()->state['swamp_start_key'])->toBe('operation:'.$op->id);
    expect($op->state)->not->toHaveKey('swamp_run_id');
    $id = $op->id;
    DB::rollBack();
    expect(Operation::query()->find($id))->toBeNull();
});

it('verifies an unbound admitted run with one current workflow search and no writes', function (): void {
    $operation = $this->operation;
    $operation->update(['state' => [...$operation->state, 'swamp_start_key' => 'operation:'.$operation->id]]);
    $before = $operation->fresh()->getRawOriginal();
    $runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static function (array $request) use ($operation, $runId): array {
        expect($request['type'])->toBe('workflow.run.search');
        expect($request['payload'])->toBe(['workflowIdOrName' => 'operation-route-publication',
            'inputs' => ['operation_id' => (string) $operation->id],
            'tags' => ['orbit_start_key' => 'operation:'.$operation->id], 'limit' => 2]);

        return [['type' => 'workflow.run.search', 'id' => $request['id'], 'payload' => ['total' => 1, 'data' => ['results' => [[
            'workflowName' => 'operation-route-publication', 'runId' => $runId, 'status' => 'running',
            'tags' => ['orbit_start_key' => 'operation:'.$operation->id],
            'inputs' => ['operation_id' => $operation->id, 'kind' => $operation->kind, 'node_id' => $operation->node_id],
        ]]]]]];
    };
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.scoped.fixture']);

    app(StartSwampRun::class)->verify($operation, $runId);

    expect($socket->sent)->toHaveCount(1);
    expect($socket->closed)->toBeTrue();
    expect($operation->fresh()->getRawOriginal())->toBe($before);
});
