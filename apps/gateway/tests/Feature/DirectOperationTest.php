<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Models\Node;
use App\Models\Operation;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $node = Node::query()->create(['name' => 'private-dev', 'public_ssh_host' => '192.0.2.9', 'user' => 'orbit', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    $this->operation = Operation::query()->create(['kind' => 'route.caddy_converge', 'target_type' => 'node_route_projection',
        'target_id' => $node->id, 'node_id' => $node->id, 'status' => OperationStatus::Queued, 'sequence' => 1,
        'state' => ['route_publication_writer' => 'direct', 'swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676'], 'input' => []]);
});

it('uses only the fixed workflow and shared token rather than Operation input', function (): void {
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.scoped.fixture']);
    $this->operation->update(['state' => ['route_publication_writer' => 'direct'], 'input' => ['workflow' => 'foreign-workflow', 'token_config' => 'foreign', 'node_id' => 100]]);
    app(StartSwampRun::class)->start($this->operation);

    expect($socket->endpoint->bearerToken)->toBe('private.scoped.fixture');
    expect($socket->sent[0]['payload'])->toBe(['workflowIdOrName' => 'operation-route-publication',
        'inputs' => ['operation_id' => $this->operation->id, 'kind' => 'route.caddy_converge', 'node_id' => $this->operation->node_id]]);
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
    foreach ([['kind' => 'tool.install'], ['kind' => 'route.caddy_converge', 'state' => []]] as $changes) {
        $this->operation->update($changes);
        expect(fn () => app(StartSwampRun::class)->start($this->operation))->toThrow(ResourceOperationException::class);
    }
    expect($socket->sent)->toBe([]);
    expect($socket->endpoint)->toBeNull();
    expect(app(DirectOperation::class)->writer('tool.install'))->toBeNull();
});

it('fails closed without a configured scoped credential before sending intent', function (): void {
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => '']);
    expect(fn () => app(StartSwampRun::class)->start($this->operation))->toThrow(ResourceOperationException::class);
    expect($socket->sent)->toBe([]);
    expect($this->operation->fresh()->state)->toBe(['route_publication_writer' => 'direct', 'swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676']);
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

it('does not overwrite a run already bound by the Gateway start response', function (): void {
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.scoped.fixture']);
    $this->operation->update(['state' => ['route_publication_writer' => 'direct', 'swamp_run_id' => 'fabd4059-fb6c-49d8-940e-01ae58aa3f38']]);
    expect(fn () => app(StartSwampRun::class)->start($this->operation))->toThrow(ResourceOperationException::class);
    expect($this->operation->fresh()->state['swamp_run_id'])->toBe('fabd4059-fb6c-49d8-940e-01ae58aa3f38');
    expect($socket->closed)->toBeTrue();
});

it('projects terminal results idempotently and rejects a changed run or outcome', function (): void {
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
