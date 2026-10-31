<?php

declare(strict_types=1);

use App\Actions\Operations\CancelOperationAction;
use App\Domain\Operations\OperationAttemptLease;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Models\Node;
use App\Models\Operation;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    config(['swamp.run_token' => 'private.scoped.fixture', 'routes.swamp_writers' => ['route.private_dns_publish' => 'direct']]);
});

function unsubmittedDirectIntent(): Operation
{
    $node = Node::query()->create(['name' => 'direct-cancel', 'status' => 'active', 'platform' => 'linux',
        'user' => 'orbit', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9']);

    return app(OperationStore::class)->create('route.private_dns_publish', 'node_route_projection', $node->id, $node->id, [], null, null, expectedWriter: 'direct');
}

function directCancellationSocket(): FakeRoutePublicationSocket
{
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);

    return $socket;
}

it('cancels a new unsubmitted direct intent through the normal HTTP route without a native receipt', function (): void {
    $operation = unsubmittedDirectIntent();
    $node = $this->markAsGateway(Node::query()->findOrFail($operation->node_id));
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $socket = directCancellationSocket();

    $this->call('POST', '/api/v1/operations/'.$operation->id.'/cancel', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')
        ->assertOk()->assertJsonPath('data.status', 'cancelled');

    $operation->refresh();
    expect($operation->error_code)->toBe('operation.cancelled');
    expect($operation->cancel_requested_at)->not->toBeNull();
    expect($operation->finished_at)->not->toBeNull();
    expect($operation->result)->toBeNull();
    expect($operation->state)->not->toHaveKeys(['swamp_run_id', 'executor_runs']);
    expect($operation->steps()->count())->toBe(0);
    app(StartSwampRun::class)->start($operation);
    expect($socket->connections)->toBe([]);
});

it('keeps older attempted and otherwise touched direct intents cancellation-request-only', function (string $case): void {
    $operation = unsubmittedDirectIntent();
    $state = [...$operation->state, 'swamp_submission' => 'unattempted'];
    $changes = match ($case) {
        'older' => ['state' => array_diff_key($state, ['swamp_submission' => true])],
        'attempted' => ['state' => [...$state, 'swamp_submission' => 'attempted']],
        'unknown state' => ['state' => [...$state, 'unproven' => true]],
        'bound' => ['state' => [...$state, 'swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676']],
        'dispatched' => ['dispatched_at' => now()],
        'started' => ['started_at' => now()],
        'attempts' => ['attempts' => 1],
        'worker' => ['worker' => 'previous-worker'],
        'lock' => ['lock_key' => 'node:'.$operation->node_id],
        'result' => ['result' => ['ready' => true]],
        'target mismatch' => ['state' => [...$state, 'executor_target' => ['node_id' => 999, 'target_type' => 'node_route_projection', 'target_id' => $operation->target_id]]],
        'steps', 'children', 'parent' => [],
    };
    $operation->update(['state' => $state, ...$changes]);
    if ($case === 'steps') {
        $operation->steps()->create(['position' => 1, 'name' => 'publish', 'status' => 'pending', 'sequence' => 1, 'resumable' => false]);
    }
    if (in_array($case, ['children', 'parent'], true)) {
        $other = Operation::query()->create(['kind' => $operation->kind, 'target_type' => 'node_route_projection', 'target_id' => $operation->target_id,
            'node_id' => $operation->node_id, 'status' => 'queued', 'sequence' => 1, 'state' => [], 'input' => []]);
        ($case === 'children' ? $other : $operation)->forceFill(['parent_operation_id' => $case === 'children' ? $operation->id : $other->id])->save();
    }
    $socket = directCancellationSocket();

    app(CancelOperationAction::class)->execute($operation);

    expect($operation->fresh()->status)->toBe(OperationStatus::Queued);
    expect($operation->fresh()->cancel_requested_at)->not->toBeNull();
    expect($operation->fresh()->finished_at)->toBeNull();
    expect($socket->connections)->toBe([]);
})->with(['older', 'attempted', 'unknown state', 'bound', 'dispatched', 'started', 'attempts', 'worker', 'lock', 'result', 'target mismatch', 'steps', 'children', 'parent']);

it('does not submit or settle while another request holds the same operation lease', function (): void {
    $operation = unsubmittedDirectIntent();
    $socket = directCancellationSocket();

    app(OperationAttemptLease::class)->hold($operation->id, function () use ($operation): void {
        expect(fn () => app(StartSwampRun::class)->start($operation))->toThrow(ResourceOperationException::class);
        app(CancelOperationAction::class)->execute($operation);
        expect($operation->fresh()->status)->toBe(OperationStatus::Queued);
        expect($operation->fresh()->cancel_requested_at)->not->toBeNull();
    });
    expect($socket->connections)->toBe([]);

    app(CancelOperationAction::class)->execute($operation);
    expect($operation->fresh()->status)->toBe(OperationStatus::Cancelled);
});

it('records attempted submission before connecting and binds an in-flight start after cancellation', function (): void {
    $operation = unsubmittedDirectIntent();
    $socket = directCancellationSocket();
    $socket->onConnect = static function () use ($operation): void {
        expect($operation->fresh()->state['swamp_submission'])->toBe('attempted');
        app(CancelOperationAction::class)->execute($operation);
        expect($operation->fresh()->status)->toBe(OperationStatus::Queued);
        expect($operation->fresh()->cancel_requested_at)->not->toBeNull();
    };

    app(StartSwampRun::class)->start($operation);

    expect($operation->fresh()->state['swamp_run_id'])->toBe('a075eb23-7ac4-4487-80d1-57a038a92676');
    expect($operation->fresh()->finished_at)->toBeNull();
    expect(array_column($socket->sent, 'type'))->toBe(['workflow.run']);
});

it('never settles an uncertain start even when its immediate history was empty', function (): void {
    $operation = unsubmittedDirectIntent();
    $socket = directCancellationSocket();
    $socket->reply = static fn (array $request): array => $request['type'] === 'workflow.run.search'
        ? [['type' => 'workflow.run.search', 'id' => $request['id'], 'payload' => ['total' => 0, 'data' => ['results' => []]]]] : [];
    expect(fn () => app(StartSwampRun::class)->start($operation))->toThrow(ResourceOperationException::class);
    $sent = $socket->sent;

    app(CancelOperationAction::class)->execute($operation);

    expect($operation->fresh()->state['swamp_submission'])->toBe('attempted');
    expect($operation->fresh()->state)->not->toHaveKey('swamp_run_id');
    expect($operation->fresh()->status)->toBe(OperationStatus::Queued);
    expect($operation->fresh()->cancel_requested_at)->not->toBeNull();
    expect($socket->sent)->toBe($sent);
});
