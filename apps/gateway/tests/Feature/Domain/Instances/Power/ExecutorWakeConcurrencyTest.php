<?php

declare(strict_types=1);

use App\Domain\Executor\ExecutorOrderBroadcast;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceLifecycleContribution;
use App\Domain\Instances\Power\ExecutorWake;
use App\Domain\Instances\Power\InstanceSleep;
use App\Domain\Instances\Power\InstanceWake;
use App\Domain\Operations\OperationStatus;
use App\Jobs\Operations\CompleteExecutorWake;
use App\Jobs\Operations\ProjectExecutorWake;
use App\Models\Instance;
use App\Models\Operation;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestDatabaseEnvironment;
use Tests\Support\TestDatabaseGuard;

require_once __DIR__.'/ExecutorWakeTestHelpers.php';

beforeEach(function (): void {
    $this->originalDatabase = getenv(TestDatabaseEnvironment::AllocatedDatabaseVariable);
    $this->database = tempnam(sys_get_temp_dir(), TestDatabaseEnvironment::AllocatedDatabasePrefix);
    $this->lockHome = $this->database.'.home';
    putenv(TestDatabaseEnvironment::AllocatedDatabaseVariable.'='.$this->database);
    config(['database.connections.sqlite.database' => $this->database, 'orbit.home' => $this->lockHome]);
    TestDatabaseGuard::assertSafe(app());
    DB::purge('sqlite');
    $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
});

afterEach(function (): void {
    DB::purge('sqlite');
    if (isset($this->database)) {
        foreach ([$this->database, $this->database.'-wal', $this->database.'-shm'] as $path) {
            File::delete($path);
        }
        File::deleteDirectory($this->lockHome);
    }
    putenv(TestDatabaseEnvironment::AllocatedDatabaseVariable.($this->originalDatabase === false ? '' : '='.$this->originalDatabase));
});

function independentWakeWriter(int $operationId): void
{
    $writer = new PDO('sqlite:'.config('database.connections.sqlite.database'));
    $writer->exec('PRAGMA busy_timeout = 0');
    $writer->exec('BEGIN IMMEDIATE');
    $statement = $writer->prepare('UPDATE operations SET sequence = sequence + 1 WHERE id = ?');
    $statement->execute([$operationId]);
    expect($statement->rowCount())->toBe(1);
    $writer->exec('COMMIT');
}

function secondSleepingInstance(Instance $instance): Instance
{
    $second = Instance::query()->create(['node_id' => $instance->node_id, 'project_id' => $instance->project_id, 'name' => 'other',
        'checkout_path' => '/workspace/other', 'root' => 'public', 'status' => 'active', 'first_setup_pending' => false]);
    app(InstanceContainer::class)->reserve($second, $instance->node)->update(['power_state' => 'stopped']);

    return $second;
}

it('admits two visits and an independent SQLite writer while the handoff queue is stalled', function (string $handoff): void {
    $instance = executorWakeInstance();
    $second = secondSleepingInstance($instance);
    $warnings = [];
    Log::listen(function (MessageLogged $event) use (&$warnings): void {
        if ($event->level === 'warning' && str_contains($event->message, 'executor wake')) {
            $warnings[] = [$event->message, $event->context['exception_type'] ?? 'unknown'];
        }
    });
    executorWakeVisit($instance)->assertUnauthorized();
    $operation = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    expect($warnings)->toBe([]);
    expect($operation->state)->toHaveKey(ExecutorWake::PublishedAt);
    $wake = app(ExecutorWake::class);
    if ($handoff === 'hook') {
        executorWakeReply($operation, ['status' => 'done']);
        expect($wake->complete($operation))->toBeTrue();
    }

    $bus = Bus::getFacadeRoot();
    $jobType = $handoff === 'hook' ? ProjectExecutorWake::class : CompleteExecutorWake::class;
    $callbackFailure = null;
    Bus::partialMock()->shouldReceive('dispatch')->once()->with(Mockery::type($jobType))->andReturnUsing(function ($job) use ($bus, $second, $operation, &$callbackFailure, &$warnings): mixed {
        try {
            independentWakeWriter($operation->id);
            executorWakeVisit($second, '/one')->assertUnauthorized();
            $joined = Operation::query()->where('kind', InstanceWake::Kind)->where('target_id', $second->id)->sole();
            $this->resultQueue->arriving = fn () => executorWakeReply($joined, ['status' => 'partial']);
            $response = executorWakeVisit($second, '/two');
            expect($warnings)->toBe([]);
            expect($joined->refresh()->executor_result)->not->toBeNull();
            $response->assertOk();
        } catch (Throwable $exception) {
            $callbackFailure = $exception;
            throw $exception;
        }

        return $bus->dispatch($job);
    });

    try {
        $recovered = $handoff === 'hook' ? $wake->recoverPendingHooks() : $wake->recoverPendingCompletions();
        if ($callbackFailure !== null) {
            throw $callbackFailure;
        }
        expect($recovered)->toBe(1);
    } finally {
        Bus::swap($bus);
    }
    expect(Operation::query()->where('kind', InstanceWake::Kind)->count())->toBe(2);
})->with(['completion', 'hook']);

it('serializes hook workers across a stale lease without holding the SQLite writer', function (): void {
    $instance = executorWakeInstance();
    $second = secondSleepingInstance($instance);
    $this->publisher->publishing = fn (ExecutorOrderBroadcast $event) => executorWakeReply(Operation::query()->findOrFail((int) $event->order->operation), ['status' => 'done']);
    $diagnostic = [];
    Log::listen(function (MessageLogged $event) use (&$diagnostic): void {
        if ($event->level === 'warning' && str_contains($event->message, 'executor wake')) {
            $diagnostic[] = [$event->message, $event->context['exception_type'] ?? 'unknown'];
        }
    });
    $response = executorWakeVisit($instance);
    expect($diagnostic)->toBe([]);
    $response->assertOk();
    $this->publisher->publishing = null;
    $operation = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    $wake = app(ExecutorWake::class);
    $this->freezeTime();
    expect($wake->recoverPendingHooks())->toBe(1);
    $original = Queue::pushed(ProjectExecutorWake::class)->sole();

    $contribution = $this->mock(InstanceLifecycleContribution::class);
    $contribution->shouldReceive('projectWake')->once()->andReturnUsing(function () use ($second, $operation, $wake): void {
        independentWakeWriter($operation->id);
        executorWakeVisit($second, '/one')->assertUnauthorized();
        executorWakeVisit($second, '/two')->assertUnauthorized();
        $this->travel(ExecutorWake::HandoffLeaseSeconds + 1)->seconds();
        expect($wake->recoverPendingHooks())->toBe(1)->and($wake->recoverPendingHooks())->toBe(0);
        app()->call([Queue::pushed(ProjectExecutorWake::class)->last(), 'handle']);
        expect($operation->refresh()->state['wake_executor_projected'] ?? false)->toBeFalse();
    });
    app()->tag([InstanceLifecycleContribution::class], InstanceLifecycleContribution::class);

    app()->call([$original, 'handle']);
    app()->call([Queue::pushed(ProjectExecutorWake::class)->last(), 'handle']);
    expect($operation->refresh())->status->toBe(OperationStatus::Succeeded)
        ->and($operation->state)->toMatchArray([ExecutorWake::FollowUpState => 'delivered', 'wake_executor_projected' => true]);
    expect($wake->recoverPendingHooks())->toBe(0);
});

it('does not inherit the multi-second SQLite busy timeout for a visitor', function (): void {
    $instance = executorWakeInstance();
    config(['orbit.executor.wait_ms' => 60]);
    $writer = new PDO('sqlite:'.$this->database);
    $writer->exec('BEGIN IMMEDIATE');
    try {
        $started = hrtime(true);
        executorWakeVisit($instance)->assertUnauthorized()->assertHeader('X-Orbit-Runtime-Activation-State', 'pending');
        expect((hrtime(true) - $started) / 1e6)->toBeLessThan(300);
        expect(Operation::query()->where('kind', InstanceWake::Kind)->exists())->toBeFalse();
        expect((int) DB::scalar('PRAGMA busy_timeout'))->toBe(5_000);
    } finally {
        $writer->exec('ROLLBACK');
    }

    config(['orbit.executor.wait_ms' => 500]);
    executorWakeVisit($instance)->assertUnauthorized();
    expect(Operation::query()->where('kind', InstanceWake::Kind)->sole())->status->toBe(OperationStatus::Running);
});

it('keeps the visitor bound for a signed freeze after the signed settings went off', function (): void {
    $instance = executorWakeInstance();
    config(['orbit.executor.power_enabled' => true, 'orbit.executor.wake_enabled' => false]);
    $sleep = app(InstanceSleep::class)->start($instance, 'cli', null);
    $sleep->update(['status' => OperationStatus::Succeeded, 'lock_key' => null, 'result' => ['sleep' => 'frozen']]);
    config(['orbit.executor.power_enabled' => false, 'orbit.executor.wait_ms' => 50]);
    $writer = new PDO('sqlite:'.$this->database);
    $writer->exec('BEGIN IMMEDIATE');
    try {
        $started = hrtime(true);
        expect(app(InstanceWake::class)->activate($instance, false, 'node'))->toBeNull();
        expect((hrtime(true) - $started) / 1e6)->toBeLessThan(300);
    } finally {
        $writer->exec('ROLLBACK');
    }
});
