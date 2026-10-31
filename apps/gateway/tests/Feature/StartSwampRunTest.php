<?php

declare(strict_types=1);

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketException;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FakeRoutePublicationSocket;

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

    DB::commit();

    expect($operation->fresh())->status->toBe(OperationStatus::Running)->error_code->toBeNull()->finished_at->toBeNull();
    expect($operation->fresh()->started_at->equalTo(now()))->toBeTrue();
    expect($operation->fresh()->dispatched_at->equalTo(now()))->toBeTrue();
    expect($operation->fresh()->state['swamp_run_id'])->toBe('a075eb23-7ac4-4487-80d1-57a038a92676');
    expect($operation->fresh()->sequence)->toBe(3);
    expect(Schema::hasTable('operation_steps'))->toBeFalse();
    expect(array_column($socket->sent, 'type'))->toBe(['workflow.run']);
});

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

it('makes a lost or malformed response terminal and never resubmits on replay', function (bool $malformed): void {
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
    expect($operation->fresh())->status->toBe(OperationStatus::Failed)->error_code->toBe('swamp.start_uncertain');
    expect($operation->fresh()->finished_at)->not->toBeNull();
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

    expect($operation->fresh())->status->toBe(OperationStatus::Failed)->error_code->toBe('swamp.start_uncertain');
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
