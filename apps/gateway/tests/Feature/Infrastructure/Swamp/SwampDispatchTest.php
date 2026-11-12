<?php

declare(strict_types=1);

use App\Actions\Operations\CancelOperationAction;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Jobs\Operations\ReconcileSwampOperations;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

require_once __DIR__.'/../../Domain/Instances/ContainerInstanceHelpers.php';

it('answers create admission with 202 before attempting the native start and replays the queued Operation', function (): void {
    Queue::fake([StartAdmittedSwampRun::class]);
    $caller = SwampNodeFixture::ensure();
    $node = containerDefaultNode();
    $project = containerDefaultProject();
    $caller->accessibleNodes()->attach($node);
    $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip]);
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldNotReceive('connect');
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => null]);
    $input = ['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default'];
    $headers = ['Idempotency-Key' => 'delayed-create'];

    $response = $this->postJson('/api/v1/instances', $input, $headers)
        ->assertAccepted()->assertJsonPath('data.status', 'queued');
    $id = $response->json('data.id');
    $this->postJson('/api/v1/instances', $input, $headers)
        ->assertAccepted()->assertJsonPath('data.id', $id);
    $this->postJson('/api/v1/instances', $input)
        ->assertConflict()->assertJsonPath('error.code', 'operation.target_busy');

    expect(Operation::query()->sole()->status)->toBe(OperationStatus::Queued);
    Queue::assertPushed(StartAdmittedSwampRun::class, fn ($job): bool => $job->operationId === $id
        && $job->connection === 'gateway' && $job->queue === 'operations');
    Queue::assertPushed(StartAdmittedSwampRun::class, 1);
});

it('fails the accepted Operation when the queued start cannot connect and permits a new same-name admission', function (): void {
    SwampNodeFixture::ensure();
    $node = containerDefaultNode();
    $project = containerDefaultProject();
    config(['swamp.run_token' => null]);

    $operation = containerCreate($project, $node);
    expect($operation->status)->toBe(OperationStatus::Queued);
    $this->artisan('queue:work', ['connection' => 'gateway', '--queue' => 'operations', '--once' => true, '--tries' => 1])->assertSuccessful();

    expect($operation->refresh()->status)->toBe(OperationStatus::Failed)
        ->and($operation->error_code)->toBe('swamp.start_unavailable')
        ->and($operation->result['error']['message'])->toBe('The native workflow start is not configured.')
        ->and($operation->lock_key)->toBeNull();
    expect(containerCreate($project, $node)->id)->not->toBe($operation->id);
});

it('starts the committed create once in the worker, including its execution copies', function (): void {
    SwampNodeFixture::ensure();
    $node = containerDefaultNode();
    $project = containerDefaultProject();
    config(['swamp.run_token' => 'test-token', 'swamp.address' => 'swamp.test', 'swamp.ca_path' => '/test/ca.pem']);
    $frames = [];
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect', 'close')->andReturnNull();
    $socket->shouldReceive('isConnected')->andReturnTrue();
    $socket->shouldReceive('send')->andReturnUsing(function (array $frame) use (&$frames): void {
        $frames[] = $frame;
    });
    $socket->shouldReceive('receive')->andReturnUsing(function () use (&$frames): array {
        $frame = end($frames);
        if ($frame['type'] === 'vault.put') {
            return [['type' => 'vault.put', 'id' => $frame['id'], 'payload' => ['data' => [
                'vaultName' => $frame['payload']['vaultName'], 'secretKey' => $frame['payload']['key'], 'vaultType' => 'local_encryption',
            ]]]];
        }

        return [['type' => 'event', 'id' => $frame['id'], 'event' => ['kind' => 'started',
            'workflowName' => 'operation-instance-create', 'runId' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']]];
    });
    app()->instance(WebSocketClient::class, $socket);

    $operation = containerCreate($project, $node);
    expect($frames)->toBe([]);
    $this->artisan('queue:work', ['connection' => 'gateway', '--queue' => 'operations', '--once' => true, '--tries' => 1])->assertSuccessful();
    app()->call([new StartAdmittedSwampRun($operation->id), 'handle']);

    expect($operation->refresh()->status)->toBe(OperationStatus::Running)
        ->and($operation->state['swamp_run_id'])->toBe('0198e15c-bf97-7c23-8f1f-61b8fe67a844');
    expect(array_column($frames, 'type'))->toBe(['vault.put', 'vault.put', 'vault.put', 'vault.put', 'workflow.run']);
});

it('fails a job rejected before it ran without exposing its exception text', function (): void {
    Queue::fake([StartAdmittedSwampRun::class]);
    SwampNodeFixture::ensure();
    $operation = containerCreate(containerDefaultProject(), containerDefaultNode());

    (new StartAdmittedSwampRun($operation->id))->failed(new RuntimeException('private exception payload'));

    expect($operation->refresh()->status)->toBe(OperationStatus::Failed)
        ->and($operation->error_code)->toBe('swamp.start_job_failed')
        ->and($operation->lock_key)->toBeNull()
        ->and(json_encode($operation->result))->not->toContain('private exception payload');
});

it('never starts a cancelled or expired admission when its job arrives late', function (bool $cancelled): void {
    Queue::fake([StartAdmittedSwampRun::class]);
    SwampNodeFixture::ensure();
    $operation = containerCreate(containerDefaultProject(), containerDefaultNode());
    if ($cancelled) {
        $operation->update(['status' => OperationStatus::Cancelled]);
    } else {
        $this->travel(601)->seconds();
    }
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldNotReceive('connect');
    app()->instance(WebSocketClient::class, $socket);

    app()->call([new StartAdmittedSwampRun($operation->id), 'handle']);

    expect($operation->refresh()->status)->toBe($cancelled ? OperationStatus::Cancelled : OperationStatus::Failed);
    if (! $cancelled) {
        expect($operation->error_code)->toBe('operation.timed_out');
    }
})->with([true, false]);

it('bounds an interrupted start by the existing admission budget without submitting it again', function (): void {
    Queue::fake([StartAdmittedSwampRun::class]);
    SwampNodeFixture::ensure();
    $operation = containerCreate(containerDefaultProject(), containerDefaultNode());
    $operation->update(['status' => OperationStatus::Running, 'started_at' => now()]);
    $job = new StartAdmittedSwampRun($operation->id);
    $job->failed(new RuntimeException('worker stopped'));
    expect($operation->refresh()->error_code)->toBe('swamp.start_uncertain');
    $this->travel(601)->seconds();

    app()->call([new ReconcileSwampOperations, 'handle']);
    app()->call([$job, 'handle']);

    expect($operation->refresh()->status)->toBe(OperationStatus::Failed)
        ->and($operation->error_code)->toBe('operation.timed_out');
});

it('pushes admission copies after commit before queuing any start and discards rolled back work', function (): void {
    Queue::fake([StartAdmittedSwampRun::class]);
    $node = SwampNodeFixture::ensure();
    $dispatch = app(SwampDispatch::class);
    $operation = Operation::query()->create(['kind' => 'instance.create', 'target_type' => 'instance', 'target_id' => 42,
        'node_id' => $node->id, 'input' => [], 'status' => OperationStatus::Queued]);
    $copied = false;
    DB::transaction(function () use ($dispatch, $operation, &$copied): void {
        $dispatch->copy(function () use (&$copied): void {
            Queue::assertNothingPushed();
            $copied = true;
        });
        $dispatch->start($operation);
        expect($copied)->toBeFalse();
        Queue::assertNothingPushed();
    });
    expect($copied)->toBeTrue();
    Queue::assertPushed(StartAdmittedSwampRun::class, 1);

    expect(fn () => DB::transaction(function () use ($dispatch, $operation): void {
        $dispatch->copy(fn () => throw new RuntimeException('a rolled-back copy must never run'));
        $dispatch->start($operation);
        throw new RuntimeException('rollback');
    }))->toThrow(RuntimeException::class, 'rollback');
    DB::transaction(fn () => null);
    Queue::assertPushed(StartAdmittedSwampRun::class, 1);
});

it('fails every admission before queuing when an admission copy fails', function (): void {
    Queue::fake([StartAdmittedSwampRun::class]);
    $node = SwampNodeFixture::ensure();
    $operations = collect([42, 43])->map(fn (int $target): Operation => Operation::query()->create([
        'kind' => 'instance.create', 'target_type' => 'instance', 'target_id' => $target,
        'node_id' => $node->id, 'input' => [], 'status' => OperationStatus::Queued,
    ]));
    $dispatch = app(SwampDispatch::class);

    expect(fn () => DB::transaction(function () use ($operations, $dispatch): void {
        foreach ($operations as $operation) {
            $dispatch->start($operation);
        }
        $dispatch->copy(fn () => throw new ResourceOperationException('swamp.vault_sync_unavailable', 'The Swamp execution copy could not be stored.', 503));
    }))->toThrow(ResourceOperationException::class);

    foreach ($operations as $operation) {
        expect($operation->refresh()->status)->toBe(OperationStatus::Failed)
            ->and($operation->error_code)->toBe('swamp.vault_sync_unavailable');
    }
    Queue::assertNothingPushed();
});

it('fails the Operation if the queue cannot accept its start', function (): void {
    SwampNodeFixture::ensure();
    Bus::shouldReceive('dispatch')->once()->with(Mockery::type(StartAdmittedSwampRun::class))
        ->andThrow(new RuntimeException('private queue error'));

    $operation = containerCreate(containerDefaultProject(), containerDefaultNode());

    expect($operation->status)->toBe(OperationStatus::Failed)
        ->and($operation->error_code)->toBe('swamp.start_enqueue_failed')
        ->and($operation->lock_key)->toBeNull()
        ->and(json_encode($operation->result))->not->toContain('private queue error');
});

it('keeps the target held when cancellation arrives while native start confirmation is in flight', function (): void {
    Queue::fake([StartAdmittedSwampRun::class]);
    SwampNodeFixture::ensure();
    $operation = containerCreate(containerDefaultProject(), containerDefaultNode());
    $operation->update(['status' => OperationStatus::Running, 'started_at' => now()]);

    app(CancelOperationAction::class)->execute($operation);

    expect($operation->refresh()->status)->toBe(OperationStatus::Running)
        ->and($operation->cancel_requested_at)->not->toBeNull()
        ->and($operation->lock_key)->toBe('instance:'.$operation->target_id);
});

it('queues only the first proof child after all copies while unrelated Operations start immediately', function (): void {
    $this->freezeSecond();
    Queue::fake([StartAdmittedSwampRun::class]);
    $node = SwampNodeFixture::ensure();
    $proof = Operation::query()->create(['kind' => 'proof.run', 'target_type' => 'node', 'target_id' => $node->id,
        'node_id' => $node->id, 'input' => ['runs' => []], 'status' => OperationStatus::Running]);
    $children = collect([42, 43, 44])->map(fn (int $id): Operation => Operation::query()->create([
        'kind' => 'proof.command', 'target_type' => 'task_group', 'target_id' => $id, 'node_id' => $node->id,
        'input' => ['proof_operation_id' => $proof->id], 'status' => OperationStatus::Queued,
        'state' => ['swamp_start' => ['key' => 'operation:'.$id, 'workflow' => 'operation-proof-run']],
    ]));
    $other = Operation::query()->create(['kind' => 'proof.verify', 'target_type' => 'task_group', 'target_id' => 45,
        'node_id' => $node->id, 'input' => ['proof_operation_id' => $proof->id], 'status' => OperationStatus::Queued]);
    $copied = [];
    DB::transaction(function () use ($children, $other, &$copied): void {
        $dispatch = app(SwampDispatch::class);
        foreach ($children as $child) {
            $dispatch->copy(function () use ($child, &$copied): void {
                Queue::assertNothingPushed();
                $copied[] = $child->id;
            });
            $dispatch->start($child);
        }
        $dispatch->start($other);
    });

    expect($copied)->toBe($children->pluck('id')->all());
    Queue::assertPushed(StartAdmittedSwampRun::class, 2);
    Queue::assertPushed(StartAdmittedSwampRun::class, fn ($job): bool => $job->operationId === $children->first()->id && $job->delay === null);
    Queue::assertPushed(StartAdmittedSwampRun::class, fn ($job): bool => $job->operationId === $other->id && $job->delay === null);
    expect($children->last()->refresh()->state['proof_child_start'])->toBe([
        'not_before' => null, 'released_at' => null, 'advanced_at' => null,
    ])->and($children->last()->state)->toHaveKey('swamp_start');
});
