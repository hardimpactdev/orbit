<?php

declare(strict_types=1);

use App\Actions\Instances\DeployInstanceAction;
use App\Domain\Gateway\GatewayQueue;
use App\Domain\Instances\Deployment\DeploymentStepName;
use App\Domain\Instances\Deployment\InstanceDeploymentRecorder;
use App\Domain\Operations\OperationAttemptLease;
use App\Domain\Operations\OperationDispatcher;
use App\Domain\Operations\OperationPoll;
use App\Domain\Operations\OperationRun;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStepPlan;
use App\Domain\Operations\OperationStepStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Operations\OperationSweeper;
use App\Domain\Operations\OperationWorker;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Models\InstanceDeployment;
use App\Models\Operation;
use App\Models\OperationStep;
use App\Models\Process;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Orb220DeploymentApiFixture;

beforeEach(function (): void {
    enable_operation_queue();
    $this->fixture = Orb220DeploymentApiFixture::create();
});

/**
 * A deploy that a worker on another host started: its plan is recorded, source preparation succeeded with the release
 * it prepared, and `$running` was running when the worker was lost.
 */
function lost_deploy(object $test, string $running): Operation
{
    $response = $test->withServerVariables(['REMOTE_ADDR' => $test->fixture->caller->wireguard_ip])
        ->call('POST', "/api/v1/instances/{$test->fixture->instance->id}/deploy", server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}');
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    DB::table('jobs')->delete();
    $store = app(OperationStore::class);
    $store->claim($operation, 'gone-host:4242:1');
    $run = new OperationRun($operation, $store);
    $run->remember('deployment_id', static fn (): int => app(InstanceDeploymentRecorder::class)->start($test->fixture->instance, 'deployment-stream-caller')->id);
    $run->plan(array_map(
        static fn (DeploymentStepName $step): OperationStepPlan => new OperationStepPlan($step->name(), $step->resumable()),
        app(DeployInstanceAction::class)->plan($test->fixture->instance),
    ));
    $run->step('source_preparation', static fn (): array => [
        'previous' => ['name' => 'initial', 'path' => '/home/deployment-stream/releases/initial', 'commit' => str_repeat('a', 40)],
        'release' => ['name' => 'resumed', 'path' => '/home/deployment-stream/releases/resumed', 'commit' => str_repeat('c', 40)],
    ]);

    foreach ($operation->steps()->get() as $step) {
        if ($step->name === $running) {
            break;
        }

        if ($step->status === OperationStepStatus::Pending) {
            $step->update(['status' => OperationStepStatus::Succeeded]);
        }
    }

    $operation->steps()->where('name', $running)->update(['status' => OperationStepStatus::Running->value]);
    $operation->update(['current_step' => $running]);

    return $operation->refresh();
}

describe('a lost worker', function (): void {
    it('resumes a resumable step and skips the steps that already succeeded', function (): void {
        $operation = lost_deploy($this, 'environment_sync');

        expect(app(OperationSweeper::class)->sweep())->toBe(1)
            ->and($operation->refresh()->status)->toBe(OperationStatus::Queued)
            ->and(run_operation_jobs())->toBe(1);

        $operation->refresh();
        expect($operation->status)->toBe(OperationStatus::Succeeded)
            ->and($operation->attempts)->toBe(2)
            ->and($operation->result['release'])->toBe('resumed')
            ->and($operation->result['selected_release'])->toBe('resumed')
            ->and($this->fixture->deployment->invocations)->toBe(0)
            ->and($this->fixture->deployment->activations)->toBe(1)
            ->and(InstanceDeployment::query()->sole()->status)->toBe('succeeded');
    });

    it('resumes a stored deploy plan without Process restart and still rejects other plan changes', function (bool $changed): void {
        $operation = lost_deploy($this, 'environment_sync');
        $operation->steps()->where('name', 'process_restart')->delete();
        if ($changed) {
            store_deploy_steps($this->fixture->instance, [
                ['name' => 'prepare', 'phase' => 'before_activation', 'command' => 'prepare-command', 'timeout_seconds' => 30],
            ]);
        }
        $runtime = Mockery::mock(ProcessRuntimeManager::class);
        $runtime->shouldNotReceive('restart');
        app()->instance(ProcessRuntimeManager::class, $runtime);
        Process::query()->create([
            'owner_type' => $this->fixture->instance->getMorphClass(), 'owner_id' => $this->fixture->instance->id, 'name' => 'horizon',
            'runtime' => 'systemd', 'runtime_config' => ['command' => ['/usr/bin/php', 'artisan', 'horizon']],
            'restart_policy' => 'always', 'desired_state' => 'running', 'status' => 'active',
        ]);

        app(OperationSweeper::class)->sweep();
        run_operation_jobs();

        expect($operation->refresh()->status)->toBe($changed ? OperationStatus::Failed : OperationStatus::Succeeded)
            ->and($operation->error_code)->toBe($changed ? 'operation.plan_changed' : null)
            ->and($operation->steps()->where('name', 'process_restart')->exists())->toBeFalse()
            ->and(collect(InstanceDeployment::query()->sole()->events)->where('phase', 'process_restart'))->toBeEmpty();
    })->with([false, true]);

    it('fails a step that runs a person command with operation.worker_lost', function (): void {
        $operation = lost_deploy($this, 'before_activation.prepare');
        app(OperationSweeper::class)->sweep();
        run_operation_jobs();

        $operation->refresh();
        $step = $operation->steps()->where('name', 'before_activation.prepare')->sole();
        $deployment = InstanceDeployment::query()->sole();

        expect($operation->status)->toBe(OperationStatus::Failed)
            ->and($operation->failed_step)->toBe('before_activation.prepare')
            ->and($operation->error_code)->toBe('operation.worker_lost')
            ->and($operation->lock_key)->toBeNull()
            ->and($step->status)->toBe(OperationStepStatus::Failed)
            ->and($step->error_code)->toBe('operation.worker_lost')
            ->and($deployment->status)->toBe('failed')
            ->and($deployment->failed_step)->toBe('before_activation')
            ->and($deployment->error_code)->toBe('operation.worker_lost')
            ->and($deployment->selected_release)->toBe('initial')
            ->and($this->fixture->deployment->activations)->toBe(0);
    });

    it('fails an operation that started too often, even at a resumable step', function (): void {
        $operation = lost_deploy($this, 'environment_sync');
        $operation->update(['attempts' => 3]);
        app(OperationSweeper::class)->sweep();
        run_operation_jobs();

        expect($operation->refresh()->status)->toBe(OperationStatus::Failed)
            ->and($operation->error_code)->toBe('operation.worker_lost')
            ->and($operation->failed_step)->toBe('environment_sync');
    });

    it('fails a resumed deploy whose steps changed with operation.plan_changed', function (): void {
        $operation = lost_deploy($this, 'environment_sync');
        store_deploy_steps($this->fixture->instance, [
            ['name' => 'prepare', 'phase' => 'before_activation', 'command' => 'prepare-command', 'timeout_seconds' => 30],
        ]);
        app(OperationSweeper::class)->sweep();
        run_operation_jobs();

        expect($operation->refresh()->status)->toBe(OperationStatus::Failed)
            ->and($operation->error_code)->toBe('operation.plan_changed')
            ->and(InstanceDeployment::query()->sole()->error_code)->toBe('operation.plan_changed');
    });
});

describe('an attempt that ends without its outcome', function (): void {
    it('never runs a step again that failed before the attempt recorded the outcome', function (): void {
        $operation = lost_deploy($this, 'before_activation.prepare');
        $operation->steps()->where('name', 'before_activation.prepare')->update(['status' => OperationStepStatus::Failed->value, 'error_code' => null]);
        app(OperationSweeper::class)->sweep();
        run_operation_jobs();

        $operation->refresh();
        expect($operation->status)->toBe(OperationStatus::Failed)
            ->and($operation->failed_step)->toBe('before_activation.prepare')
            ->and($operation->error_code)->toBe('operation.worker_lost')
            ->and($operation->lock_key)->toBeNull()
            ->and($this->fixture->deployment->steps)->toBe([])
            ->and($this->fixture->deployment->activations)->toBe(0)
            ->and(InstanceDeployment::query()->sole()->failed_step)->toBe('before_activation');
    });

    it('frees the Instance after an error escapes the attempt, even while the worker process lives on', function (): void {
        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip])
            ->call('POST', "/api/v1/instances/{$this->fixture->instance->id}/deploy", server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}');
        $operation = Operation::query()->findOrFail($response->json('data.id'));
        // Writes fail as while the database is locked: the steps cannot change, and the attempt cannot even record its failure.
        DB::statement("CREATE TRIGGER operation_steps_locked BEFORE UPDATE ON operation_steps BEGIN SELECT RAISE(ABORT, 'database is locked'); END");
        DB::statement("CREATE TRIGGER operations_locked BEFORE UPDATE OF status ON operations WHEN NEW.status = 'failed' BEGIN SELECT RAISE(ABORT, 'database is locked'); END");

        expect(static fn () => run_operation_jobs())->toThrow(DeadlockException::class)
            ->and($operation->refresh()->status)->toBe(OperationStatus::Running)
            ->and($operation->lock_key)->not->toBeNull();

        DB::statement('DROP TRIGGER operation_steps_locked');
        DB::statement('DROP TRIGGER operations_locked');
        DB::table('jobs')->delete();

        expect(app(OperationSweeper::class)->sweep())->toBe(1)
            ->and(run_operation_jobs())->toBe(1)
            ->and($operation->refresh()->status)->toBe(OperationStatus::Succeeded)
            ->and($operation->lock_key)->toBeNull()
            ->and($operation->attempts)->toBe(2);
    });
});

describe('the sweep', function (): void {
    it('leaves an operation whose attempt still holds its lease, and requeues it once the lease is free', function (): void {
        $operation = lost_deploy($this, 'environment_sync');
        $operation->update(['worker' => app(OperationWorker::class)->current()]);
        $swept = null;

        app(OperationAttemptLease::class)->hold($operation->id, static function () use (&$swept): void {
            $swept = app(OperationSweeper::class)->sweep();
        });

        expect($swept)->toBe(0)
            ->and($operation->refresh()->status)->toBe(OperationStatus::Running)
            // The worker process still lives, but no attempt holds the lease any more.
            ->and(app(OperationSweeper::class)->sweep())->toBe(1)
            ->and($operation->refresh()->status)->toBe(OperationStatus::Queued);
    });

    it('adds no second job for an operation that waits in the queue longer than the lost-job time', function (): void {
        // One worker runs a long deploy; this one waits behind it with its job still in the queue.
        $waiting = app(OperationStore::class)->create('instance.deploy', 'instance', $this->fixture->instance->id, null, [], 'test', null);
        app(OperationDispatcher::class)->dispatch($waiting);
        $waiting->update(['dispatched_at' => Carbon::now()->subSeconds(OperationSweeper::QueuedJobLostSeconds * 3)]);

        expect(app(OperationSweeper::class)->sweep())->toBe(0)
            ->and(app(OperationSweeper::class)->sweep())->toBe(0)
            ->and(DB::table('jobs')->where('queue', GatewayQueue::Operations->value)->count())->toBe(1);
    });

    it('queues a job again only for a queued operation whose job was lost', function (): void {
        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip])
            ->call('POST', "/api/v1/instances/{$this->fixture->instance->id}/deploy", server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}');
        $operation = Operation::query()->findOrFail($response->json('data.id'));
        DB::table('jobs')->delete();

        expect(app(OperationSweeper::class)->sweep())->toBe(0);

        $operation->update(['dispatched_at' => Carbon::now()->subSeconds(OperationSweeper::QueuedJobLostSeconds + 1)]);

        expect(app(OperationSweeper::class)->sweep())->toBe(1)
            ->and(DB::table('jobs')->sole()->queue)->toBe(GatewayQueue::Operations->value);
    });
});

describe('the attempt lease', function (): void {
    it('lets one holder run an attempt at a time, and frees the lease when the attempt throws', function (): void {
        $lease = new OperationAttemptLease(sys_get_temp_dir().'/orbit-operation-lease-'.bin2hex(random_bytes(4)));
        $inner = null;

        expect($lease->hold(7, static function () use ($lease, &$inner): void {
            $inner = $lease->hold(7, static fn (): null => null);
        }))->toBeTrue()
            ->and($inner)->toBeFalse()
            ->and(static fn () => $lease->hold(7, static fn () => throw new RuntimeException('database is locked')))->toThrow(RuntimeException::class)
            ->and($lease->hold(7, static fn (): null => null))->toBeTrue();
    });

    it('names a worker by host and process ID', function (): void {
        expect(new OperationWorker('gateway', 77)->current())->toBe('gateway:77');
    });
});

describe('polling', function (): void {
    it('waits 2 seconds at first, grows with run time to 15, and stops once terminal', function (): void {
        Carbon::setTestNow('2026-10-15 10:00:00');
        $operation = new Operation(['status' => OperationStatus::Queued]);

        expect(OperationPoll::afterSeconds($operation))->toBe(2);

        $operation->started_at = Carbon::now()->subSeconds(9);
        $operation->status = OperationStatus::Running;
        expect(OperationPoll::afterSeconds($operation))->toBe(2);

        $operation->started_at = Carbon::now()->subSeconds(45);
        expect(OperationPoll::afterSeconds($operation))->toBe(6);

        $operation->started_at = Carbon::now()->subHour();
        expect(OperationPoll::afterSeconds($operation))->toBe(15);

        $operation->status = OperationStatus::Succeeded;
        expect(OperationPoll::afterSeconds($operation))->toBeNull();
    });
});

describe('step output', function (): void {
    it('writes the output tail at most once a second while the step runs', function (): void {
        $operation = app(OperationStore::class)->create('instance.deploy', 'instance', $this->fixture->instance->id, null, [], 'test', null);
        $now = 100.0;
        $run = new OperationRun($operation, app(OperationStore::class), static function () use (&$now): float {
            return $now;
        });
        $run->plan([new OperationStepPlan('work', true)]);
        $seen = [];

        $run->step('work', static function () use ($run, &$now, &$seen, $operation): null {
            $run->output("one\n");
            $seen[] = OperationStep::query()->where('operation_id', $operation->id)->value('output');
            $now += 1.5;
            $run->output("two\n");
            $seen[] = OperationStep::query()->where('operation_id', $operation->id)->value('output');

            return null;
        });

        expect($seen)->toBe([null, "one\ntwo\n"])
            ->and(OperationStep::query()->sole()->status)->toBe(OperationStepStatus::Succeeded);
    });
});
