<?php

declare(strict_types=1);

use App\Actions\Instances\DeployInstanceAction;
use App\Domain\Gateway\GatewayQueue;
use App\Domain\Instances\Deployment\DeploymentStepName;
use App\Domain\Instances\Deployment\InstanceDeploymentRecorder;
use App\Domain\Operations\OperationPoll;
use App\Domain\Operations\OperationRun;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStepPlan;
use App\Domain\Operations\OperationStepStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Operations\OperationSweeper;
use App\Domain\Operations\OperationWorker;
use App\Models\InstanceDeployment;
use App\Models\Operation;
use App\Models\OperationStep;
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

describe('the sweep', function (): void {
    it('leaves an operation whose worker still runs', function (): void {
        $operation = lost_deploy($this, 'environment_sync');
        $operation->update(['worker' => app(OperationWorker::class)->current()]);

        expect(app(OperationSweeper::class)->sweep())->toBe(0)
            ->and($operation->refresh()->status)->toBe(OperationStatus::Running);
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

describe('the worker probe', function (): void {
    beforeEach(function (): void {
        $this->proc = sys_get_temp_dir().'/orbit-operation-proc-'.bin2hex(random_bytes(4));
        mkdir("{$this->proc}/77", recursive: true);
        file_put_contents("{$this->proc}/77/stat", '77 (php artisan (x)) S 1 77 77 0 -1 4194560 '.implode(' ', array_fill(0, 12, '0')).' 123456 0 0');
    });

    afterEach(function (): void {
        @unlink("{$this->proc}/77/stat");
        @rmdir("{$this->proc}/77");
        @rmdir($this->proc);
    });

    it('names a worker by host, process ID, and start time, and knows it while that process runs', function (): void {
        $worker = new OperationWorker($this->proc, 'gateway', 77);

        expect($worker->current())->toBe('gateway:77:123456')
            ->and($worker->alive('gateway:77:123456'))->toBeTrue()
            ->and($worker->alive('gateway:77:999'))->toBeFalse()
            ->and($worker->alive('gateway:78:123456'))->toBeFalse()
            ->and($worker->alive('other:77:123456'))->toBeFalse()
            ->and($worker->alive('nonsense'))->toBeFalse();
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
