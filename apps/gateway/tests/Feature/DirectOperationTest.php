<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketException;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $node = Node::query()->create(['name' => 'private-dev', 'public_ssh_host' => '192.0.2.9', 'user' => 'orbit', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    $this->operation = Operation::query()->create(['kind' => 'route.private_dns_publish', 'target_type' => 'node_route_projection',
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
        'inputs' => ['operation_id' => $this->operation->id, 'kind' => 'route.private_dns_publish', 'node_id' => $this->operation->node_id],
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
        'started_at' => '2026-10-30T12:00:00.000Z'], []))->toThrow(ResourceOperationException::class);
    expect($this->operation->fresh()->state)->toBe(['route_publication_writer' => 'direct']);
    expect(Schema::hasTable('operation_steps'))->toBeFalse();
});

it('refuses undeclared intents and registers direct declarations without writer flags', function (): void {
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.scoped.fixture']);
    $this->operation->update(['kind' => 'unknown.intent']);
    expect(fn () => app(StartSwampRun::class)->start($this->operation))->toThrow(ResourceOperationException::class);
    expect(fn () => app(DirectOperation::class)->writer('unknown.intent'))->toThrow(ResourceOperationException::class);
    expect($socket->sent)->toBe([]);
    $this->operation->update(['kind' => 'route.private_dns_publish', 'state' => [], 'status' => OperationStatus::Queued]);
    app(StartSwampRun::class)->start($this->operation);
    expect($this->operation->fresh()->state['swamp_run_id'])->toBe('a075eb23-7ac4-4487-80d1-57a038a92676');
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
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Failed);
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
    $direct->project($this->operation, $data, ['ready' => true]);
    $sequence = $this->operation->sequence;
    $direct->project($this->operation, $data, ['ready' => true]);
    expect($this->operation->sequence)->toBe($sequence);
    expect($this->operation->attempts)->toBe(0);
    expect($this->operation->worker)->toBeNull();
    expect(Schema::hasTable('operation_steps'))->toBeFalse();
    expect($this->operation->state)->not->toHaveKey('steps');
    expect($this->operation->current_step)->toBeNull();
    expect(fn () => $direct->project($this->operation, $data, ['ready' => false]))->toThrow(ResourceOperationException::class);
    expect(fn () => $direct->project($this->operation, [...$data, 'run_id' => 'fabd4059-fb6c-49d8-940e-01ae58aa3f38'], ['ready' => true]))->toThrow(ResourceOperationException::class);
});

it('finishes a lost start response without a history request or another submission', function (): void {
    $socket = new FakeRoutePublicationSocket(false);
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.scoped.fixture']);

    expect(fn () => app(StartSwampRun::class)->start($this->operation))->toThrow(ResourceOperationException::class, 'Swamp did not confirm');

    expect($this->operation->fresh()->status)->toBe(OperationStatus::Failed);
    expect($this->operation->fresh()->error_code)->toBe('swamp.start_uncertain');
    expect(array_column($socket->sent, 'type'))->toBe(['workflow.run']);
    expect($socket->closed)->toBeTrue();
});

it('does not resubmit a lost response even when a later response would succeed', function (): void {
    $socket = new FakeRoutePublicationSocket;
    $submissions = 0;
    $socket->reply = static function (array $request) use (&$submissions): array {
        $submissions++;
        if ($submissions === 1) {
            return [];
        }

        return [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started',
            'workflowName' => 'operation-route-publication', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    };
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.scoped.fixture']);

    expect(fn () => app(StartSwampRun::class)->start($this->operation))->toThrow(ResourceOperationException::class);
    app(StartSwampRun::class)->start($this->operation);

    expect($submissions)->toBe(1);
    expect($this->operation->fresh()->state)->not->toHaveKey('swamp_run_id');
    expect($this->operation->fresh()->error_code)->toBe('swamp.start_uncertain');
});

it('makes a connection failure terminal without reading native history', function (): void {
    $socket = new FakeRoutePublicationSocket;
    $socket->onConnect = static function (): void {
        throw new WebSocketException('private.scoped.fixture', 504);
    };
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.scoped.fixture']);

    expect(fn () => app(StartSwampRun::class)->start($this->operation))->toThrow(ResourceOperationException::class);

    expect($this->operation->fresh()->status)->toBe(OperationStatus::Failed);
    expect($this->operation->fresh()->error_code)->toBe('swamp.start_unavailable');
    expect($socket->sent)->toBe([]);
    expect($socket->connections)->toBe([15.0]);
});

it('returns an existing unbound idempotency receipt without submitting native work', function (): void {
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.scoped.fixture']);
    $this->operation->update(['idempotency_key' => 'same-intent', 'idempotency_fingerprint' => str_repeat('a', 64)]);
    $identity = app(OperationIdempotency::class);
    $identity->kind = $this->operation->kind;
    $identity->key = 'same-intent';
    $identity->fingerprint = str_repeat('a', 64);
    DB::beginTransaction();
    $replayed = $identity->replay($this->operation->kind);
    DB::commit();

    expect($replayed->id)->toBe($this->operation->id);
    expect($socket->sent)->toBe([]);
    expect($socket->connections)->toBe([]);
});

it('never resubmits bound terminal running or cancellation-requested intents', function (array $changes): void {
    $this->operation->update($changes);
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.scoped.fixture']);

    app(StartSwampRun::class)->start($this->operation);

    expect($socket->connections)->toBe([]);
})->with([
    'bound' => [['state' => ['route_publication_writer' => 'direct', 'swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]],
    'succeeded' => [['status' => OperationStatus::Succeeded]],
    'failed' => [['status' => OperationStatus::Failed]],
    'cancelled' => [['status' => OperationStatus::Cancelled]],
    'running' => [['status' => OperationStatus::Running]],
    'cancellation requested' => [fn (): array => ['cancel_requested_at' => now()]],
]);

it('refuses a retained admission tag without native history calls until the start is bound', function (): void {
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.scoped.fixture']);
    $this->operation->update(['state' => ['route_publication_writer' => 'direct', 'swamp_start_key' => 'operation:'.$this->operation->id]]);

    try {
        app(DirectOperation::class)->assertRun($this->operation, 'a075eb23-7ac4-4487-80d1-57a038a92676');
        $this->fail('Expected an unbound run refusal');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('operation.run_unbound');
    }

    expect($socket->sent)->toBe([]);
    expect($socket->connections)->toBe([]);
    expect($this->operation->fresh()->state)->not->toHaveKey('swamp_run_id');
});
