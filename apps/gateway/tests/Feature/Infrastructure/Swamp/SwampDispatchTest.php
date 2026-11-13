<?php

declare(strict_types=1);

use App\Actions\Operations\CancelOperationAction;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Jobs\Operations\ReconcileSwampOperations;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

it('logs only start phase numbers while preserving the socket sequence', function (): void {
    $node = SwampNodeFixture::ensure();
    config(['swamp.run_token' => 'phase-log-private-token', 'swamp.address' => 'swamp.test', 'swamp.ca_path' => '/test/ca.pem']);
    $marker = 'private-payload-marker/☃';
    $operation = Operation::query()->create([
        'kind' => 'proof.verify', 'target_type' => 'task', 'target_id' => 42, 'node_id' => $node->id,
        'input' => ['command' => 'fixture:phase', 'marker' => $marker], 'status' => OperationStatus::Queued,
    ]);
    $operation->forceFill(['created_at' => now()->subSeconds(30)])->save();
    $frames = $calls = [];
    $reads = 0;
    $run = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect')->once()->andReturnUsing(function () use (&$calls): void {
        $calls[] = 'connect';
    });
    $socket->shouldReceive('send')->once()->andReturnUsing(function (array $frame) use (&$frames, &$calls): void {
        $calls[] = 'send:'.$frame['type'];
        $frames[] = $frame;
    });
    $socket->shouldReceive('isConnected')->twice()->andReturnUsing(function () use (&$calls): bool {
        $calls[] = 'isConnected';

        return true;
    });
    $socket->shouldReceive('receive')->twice()->andReturnUsing(function () use (&$reads, &$calls, &$frames, $run): array {
        $calls[] = 'receive';
        $event = ['kind' => 'started', 'workflowName' => 'proof-fleet-verify', 'runId' => $run];
        if (++$reads === 1) {
            return [
                ['type' => 'event', 'id' => 'unrelated', 'event' => $event],
                ['type' => 'event', 'id' => $frames[0]['id'], 'event' => [...$event, 'parentRunId' => $run]],
            ];
        }

        return [['type' => 'event', 'id' => $frames[0]['id'], 'event' => $event]];
    });
    $socket->shouldReceive('close')->once()->andReturnUsing(function () use (&$calls, $operation): void {
        expect($operation->fresh()->state)->not->toHaveKey('swamp_run_id');
        $calls[] = 'close';
    });
    app()->instance(WebSocketClient::class, $socket);
    Log::spy();

    app()->call([new StartAdmittedSwampRun($operation->id), 'handle']);

    expect($calls)->toBe(['connect', 'send:workflow.run', 'isConnected', 'receive', 'isConnected', 'receive', 'close']);
    expect(array_column($frames, 'type'))->toBe(['workflow.run']);
    expect($frames[0]['payload'])->toEqual([
        'workflowIdOrName' => 'proof-fleet-verify',
        'inputs' => ['intent' => ['command' => 'fixture:phase', 'marker' => $marker], 'execution_secrets' => (object) [],
            'operation_id' => $operation->id, 'kind' => 'proof.verify', 'node_id' => $node->id],
        'runtimeTags' => ['orbit_start_key' => 'operation:'.$operation->id],
    ]);
    expect($operation->fresh()->state['swamp_run_id'])->toBe($run);
    Log::shouldHaveReceived('info')->once()->with('Swamp direct start', Mockery::on(function (array $context) use ($operation): bool {
        $phases = ['inputs_ms', 'connect_ms', 'send_ms', 'first_event_ms', 'bind_ms', 'broadcast_ms'];
        expect(array_keys($context))->toBe(['operation_id', 'outcome', 'duration_ms', 'kind', ...$phases, 'queue_wait_ms']);
        expect($context['operation_id'])->toBe($operation->id)
            ->and($context['kind'])->toBe('proof.verify')
            ->and($context['outcome'])->toBe('started');
        foreach (['duration_ms', ...$phases, 'queue_wait_ms'] as $key) {
            expect($context[$key])->toBeInt()->toBeGreaterThanOrEqual(0);
        }
        expect(array_sum(array_intersect_key($context, array_flip($phases))))->toBeLessThanOrEqual($context['duration_ms']);
        expect($context['queue_wait_ms'])->toBeGreaterThanOrEqual(30_000)->toBeLessThan(33_000);
        expect(json_encode($context))->not->toContain('private-payload-marker', 'phase-log-private-token', 'workflowIdOrName', 'execution_secrets');

        return true;
    }));
    Log::shouldNotHaveReceived('warning');
});

it('keeps failed start and receive failure diagnostics unchanged', function (): void {
    $node = SwampNodeFixture::ensure();
    config(['swamp.run_token' => 'phase-log-private-token', 'swamp.address' => 'swamp.test', 'swamp.ca_path' => '/test/ca.pem']);
    $operation = Operation::query()->create(['kind' => 'proof.verify', 'target_type' => 'task', 'target_id' => 42,
        'node_id' => $node->id, 'input' => [], 'status' => OperationStatus::Queued]);
    $calls = [];
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect')->once()->andReturnUsing(function () use (&$calls): void {
        $calls[] = 'connect';
    });
    $socket->shouldReceive('send')->once()->andReturnUsing(function (array $frame) use (&$calls): void {
        $calls[] = 'send:'.$frame['type'];
    });
    $socket->shouldReceive('isConnected')->once()->andReturnTrue();
    $socket->shouldReceive('receive')->once()->andThrow(new RuntimeException('private-payload-must-not-appear'));
    $socket->shouldReceive('close')->once()->andReturnUsing(function () use (&$calls): void {
        $calls[] = 'close';
    });
    app()->instance(WebSocketClient::class, $socket);
    Log::spy();

    expect(fn () => app()->call([new StartAdmittedSwampRun($operation->id), 'handle']))->toThrow(ResourceOperationException::class);

    expect($calls)->toBe(['connect', 'send:workflow.run', 'close']);
    Log::shouldHaveReceived('info')->once()->with('Swamp direct start', Mockery::on(function (array $context) use ($operation): bool {
        expect(array_keys($context))->toBe(['operation_id', 'outcome', 'duration_ms']);
        expect($context['operation_id'])->toBe($operation->id)
            ->and($context['outcome'])->toBe('swamp.start_uncertain')
            ->and($context['duration_ms'])->toBeInt()->toBeGreaterThanOrEqual(0);

        return true;
    }));
    Log::shouldHaveReceived('warning')->once()->with('Swamp direct start response unavailable', Mockery::on(function (array $context) use ($operation): bool {
        expect(array_keys($context))->toBe(['operation_id', 'workflow', 'request_id', 'stage', 'elapsed_ms', 'confirmation_seconds', 'exception_type']);
        expect($context['operation_id'])->toBe($operation->id)
            ->and($context['workflow'])->toBe('proof-fleet-verify')
            ->and($context['stage'])->toBe('receive')
            ->and($context['elapsed_ms'])->toBeInt()->toBeGreaterThanOrEqual(0)
            ->and($context['confirmation_seconds'])->toBe(120)
            ->and($context['exception_type'])->toBe(RuntimeException::class);
        expect(json_encode($context))->not->toContain('private-payload-must-not-appear', 'phase-log-private-token');

        return true;
    }));
});

it('keeps the queued job successful and bound when success logging throws, with monotonic phase times', function (): void {
    $node = SwampNodeFixture::ensure();
    config(['swamp.run_token' => 'test-token', 'swamp.address' => 'swamp.test', 'swamp.ca_path' => '/test/ca.pem']);
    $operation = Operation::query()->create(['kind' => 'proof.verify', 'target_type' => 'task', 'target_id' => 42,
        'node_id' => $node->id, 'input' => [], 'status' => OperationStatus::Queued]);
    $frames = $context = [];
    $run = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect', 'close')->once()->andReturnNull();
    $socket->shouldReceive('send')->once()->andReturnUsing(function (array $frame) use (&$frames): void {
        $frames[] = $frame;
    });
    $socket->shouldReceive('isConnected')->once()->andReturnTrue();
    $socket->shouldReceive('receive')->once()->andReturnUsing(function () use (&$frames, $run): array {
        $this->travel(-1)->hours();

        return [['type' => 'event', 'id' => $frames[0]['id'], 'event' => ['kind' => 'started',
            'workflowName' => 'proof-fleet-verify', 'runId' => $run]]];
    });
    $tick = 0.0;
    app()->instance(StartSwampRun::class, new StartSwampRun($socket, static function () use (&$tick): float {
        return $tick += 0.125;
    }));
    Log::spy();
    Log::shouldReceive('info')->once()->with('Swamp direct start', Mockery::type('array'))
        ->andReturnUsing(function (string $message, array $logged) use (&$context): never {
            $context = $logged;
            throw new RuntimeException('log-write-failed');
        });
    Bus::dispatch(new StartAdmittedSwampRun($operation->id));

    $this->artisan('queue:work', ['connection' => 'gateway', '--queue' => 'operations', '--once' => true, '--tries' => 1])->assertSuccessful();
    app()->call([new StartAdmittedSwampRun($operation->id), 'handle']);

    expect($operation->refresh()->status)->toBe(OperationStatus::Running)
        ->and($operation->state['swamp_run_id'])->toBe($run)
        ->and($operation->error_code)->toBeNull();
    $this->assertDatabaseCount('failed_jobs', 0);
    expect(array_column($frames, 'type'))->toBe(['workflow.run']);
    $phases = ['inputs_ms', 'connect_ms', 'send_ms', 'first_event_ms', 'bind_ms', 'broadcast_ms'];
    foreach ($phases as $key) {
        expect($context[$key])->toBeInt()->toBeGreaterThanOrEqual(125);
    }
    expect($context['duration_ms'])->toBe(1875)
        ->and(array_sum(array_intersect_key($context, array_flip($phases))))->toBeLessThanOrEqual($context['duration_ms']);
    expect($context['queue_wait_ms'])->toBeInt()->toBeGreaterThanOrEqual(0);
    expect($context)->not->toHaveKey('payload_bytes');
    Log::shouldNotHaveReceived('warning');
    $this->travelBack();
});
