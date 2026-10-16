<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceDeployAction;
use App\Actions\Operations\CancelOperationAction;
use App\Domain\Instances\Deployment\AutomaticDeployHistory;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStepStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Models\Activity;
use App\Models\InstanceDeployment;
use App\Models\Operation;
use App\Models\Process;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\Orb220DeploymentApiFixture;
use Tests\Support\ProcessesApiFakeRuntimeManager;

beforeEach(function (): void {
    enable_operation_queue();
    $this->fixture = Orb220DeploymentApiFixture::create();
    $this->asCaller = fn () => $this->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip]);
});

function start_rollback(object $test, string $release = 'initial'): TestResponse
{
    return ($test->asCaller)()->call('POST', "/api/v1/instances/{$test->fixture->instance->id}/rollback", server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], content: json_encode(['release' => $release], JSON_THROW_ON_ERROR));
}

/** Queues a deploy of the fixture's Instance, as a person or as the automatic deployment tick. */
function queue_rollback_test_deploy(object $test, bool $automatic): Operation
{
    return $automatic
        ? app(StartInstanceDeployAction::class)->execute($test->fixture->instance, AutomaticDeployHistory::Trigger, null, str_repeat('b', 40))
        : Operation::query()->findOrFail(($test->asCaller)()->call('POST', "/api/v1/instances/{$test->fixture->instance->id}/deploy", server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: '{}')->assertAccepted()->json('data.id'));
}

/** @return list<int> */
function rollback_test_lock_holders(): array
{
    return Operation::query()->whereNotNull('lock_key')->orderBy('id')->pluck('id')->all();
}

describe('starting a rollback', function (): void {
    it('answers 202 with a queued operation that holds the Instance and records the release input', function (): void {
        start_rollback($this)->assertAccepted()
            ->assertJsonPath('data.kind', 'instance.rollback')
            ->assertJsonPath('data.target_type', 'instance')
            ->assertJsonPath('data.target_id', $this->fixture->instance->id)
            ->assertJsonPath('data.input', ['release' => 'initial'])
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.poll_after_seconds', 2)
            ->assertJsonPath('data.triggered_by', $this->fixture->caller->name);

        $activity = Activity::query()->sole();

        expect(Operation::query()->sole()->lock_key)->toBe("instance:{$this->fixture->instance->id}")
            ->and($activity->command)->toBe('instance:rollback')
            ->and($activity->status)->toBe('succeeded')
            ->and($activity->properties?->get('input'))->toBe(['release' => 'initial'])
            ->and(InstanceDeployment::query()->count())->toBe(0)
            ->and($this->fixture->deployment->invocations)->toBe(0);
    });

    it('refuses an Instance that cannot be rolled back before it records anything', function (): void {
        $this->fixture->instance->update(['deployment_branch' => 'bad..branch']);

        start_rollback($this)->assertStatus(409)->assertJsonPath('error.code', 'deployment_config.unavailable');

        expect(Operation::query()->count())->toBe(0);
    });

    it('refuses a second rollback while the first one holds the Instance', function (): void {
        $first = start_rollback($this)->json('data.id');

        start_rollback($this)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'operation.target_busy')
            ->assertJsonPath('error.details.operation_id', $first);

        expect(rollback_test_lock_holders())->toBe([$first]);
    });
});

describe('a rollback and a deploy of the same Instance', function (): void {
    it('cancels a deploy that waits in the queue and takes the Instance', function (bool $automatic): void {
        $deploy = queue_rollback_test_deploy($this, $automatic);

        $rollback = start_rollback($this)->assertAccepted()->json('data.id');
        $deploy->refresh();

        expect($deploy->status)->toBe(OperationStatus::Cancelled)
            ->and($deploy->error_code)->toBe('operation.superseded')
            ->and($deploy->cancel_requested_at)->not->toBeNull()
            ->and($deploy->finished_at)->not->toBeNull()
            ->and(rollback_test_lock_holders())->toBe([$rollback]);

        ($this->asCaller)()->getJson("/api/v1/operations/{$deploy->id}")->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.error_code', 'operation.superseded')
            ->assertJsonPath('data.cancel_requested', true);

        expect(run_operation_jobs())->toBe(2)
            ->and($this->fixture->deployment->preparedCommits)->toBe([])
            ->and($this->fixture->deployment->requestedRelease)->toBe('initial')
            ->and(Operation::query()->findOrFail($rollback)->status)->toBe(OperationStatus::Succeeded)
            ->and(InstanceDeployment::query()->sole()->kind)->toBe(InstanceDeployment::Rollback);
    })->with(['manual' => false, 'automatic' => true]);

    it('refuses while a worker runs the deploy', function (bool $automatic): void {
        $deploy = queue_rollback_test_deploy($this, $automatic);
        expect(app(OperationStore::class)->claim($deploy, 'gateway:1'))->toBeTrue();

        start_rollback($this)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'operation.target_busy')
            ->assertJsonPath('error.details.operation_id', $deploy->id);

        expect($deploy->refresh()->status)->toBe(OperationStatus::Running)
            ->and($deploy->error_code)->toBeNull()
            ->and(rollback_test_lock_holders())->toBe([$deploy->id])
            ->and(Operation::query()->where('kind', 'instance.rollback')->count())->toBe(0);
    })->with(['manual' => false, 'automatic' => true]);

    it('refuses while a deploy that a lost worker started waits to resume', function (): void {
        $deploy = queue_rollback_test_deploy($this, automatic: false);
        $deploy->forceFill(['attempts' => 1])->save();

        start_rollback($this)->assertStatus(409)->assertJsonPath('error.details.operation_id', $deploy->id);

        expect($deploy->refresh()->status)->toBe(OperationStatus::Queued)
            ->and(rollback_test_lock_holders())->toBe([$deploy->id]);
    });

    it('leaves one holder when the deploy starts between the check and the cancel', function (): void {
        $deploy = queue_rollback_test_deploy($this, automatic: false);
        $claimed = false;
        DB::listen(static function (QueryExecuted $query) use ($deploy, &$claimed): void {
            // The worker claims the deploy right after the rollback found it queued.
            if (! $claimed && str_contains($query->sql, '"kind" in')) {
                $claimed = true;
                app(OperationStore::class)->claim($deploy, 'gateway:1');
            }
        });

        start_rollback($this)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'operation.target_busy')
            ->assertJsonPath('error.details.operation_id', $deploy->id);

        expect($claimed)->toBeTrue()
            ->and($deploy->refresh()->status)->not->toBe(OperationStatus::Cancelled)
            ->and($deploy->error_code)->toBeNull()
            ->and(rollback_test_lock_holders())->toBe([$deploy->id])
            ->and(Operation::query()->where('kind', 'instance.rollback')->count())->toBe(0);
    });

    it('refuses a deploy while a rollback holds the Instance', function (): void {
        $rollback = start_rollback($this)->json('data.id');

        ($this->asCaller)()->call('POST', "/api/v1/instances/{$this->fixture->instance->id}/deploy", server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: '{}')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'operation.target_busy')
            ->assertJsonPath('error.details.operation_id', $rollback);
    });
});

describe('running a rollback', function (): void {
    it('runs the rollback as named steps and ends with its result', function (): void {
        $this->fixture->deployment->current = 'fresh';
        $id = start_rollback($this)->json('data.id');

        expect(run_operation_jobs())->toBe(1);

        $show = ($this->asCaller)()->getJson("/api/v1/operations/{$id}");
        $deployment = InstanceDeployment::query()->sole();

        $show->assertOk()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.result', [
                'deployment_id' => $deployment->id,
                'release' => 'initial',
                'selected_release' => 'initial',
                'commit' => str_repeat('a', 40),
            ]);

        expect(array_column($show->json('data.steps'), 'name'))->toBe(['rollback', 'activation', 'php_refresh', 'process_restart'])
            ->and(array_unique(array_column($show->json('data.steps'), 'status')))->toBe(['succeeded'])
            ->and(array_column($show->json('data.steps'), 'resumable'))->toBe([true, true, true, true])
            ->and($this->fixture->deployment->requestedRelease)->toBe('initial')
            ->and($this->fixture->deployment->current)->toBe('initial')
            ->and($deployment->kind)->toBe(InstanceDeployment::Rollback)
            ->and($deployment->status)->toBe('succeeded')
            ->and($deployment->selected_release)->toBe('initial')
            ->and($deployment->triggered_by)->toBe($this->fixture->caller->name)
            ->and(collect($deployment->events)->where('type', 'phase')->pluck('phase')->all())->toBe(['rollback', 'activation', 'php_refresh', 'process_restart'])
            ->and(rollback_test_lock_holders())->toBe([]);
    });

    it('fails at the rollback step when the release is not retained', function (): void {
        $this->fixture->deployment->failRollback = true;
        $id = start_rollback($this)->json('data.id');
        run_operation_jobs();

        ($this->asCaller)()->getJson("/api/v1/operations/{$id}")
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.failed_step', 'rollback')
            ->assertJsonPath('data.error_code', 'deployment.release_missing')
            ->assertJsonPath('data.result.selected_release', 'initial');

        $deployment = InstanceDeployment::query()->sole();
        expect($deployment->failed_step)->toBe('rollback_selection')
            ->and($deployment->error_code)->toBe('deployment.release_missing')
            ->and($this->fixture->deployment->activations)->toBe(0);
    });

    it('records Process restart results on the rollback release', function (bool $fails): void {
        $runtime = new ProcessesApiFakeRuntimeManager;
        if ($fails) {
            $runtime->restartFailure = new ProcessOperationException('restart', 'process.restart_failed', 'private runtime output');
        }
        app()->instance(ProcessRuntimeManager::class, $runtime);
        $process = Process::query()->create([
            'owner_type' => $this->fixture->instance->getMorphClass(), 'owner_id' => $this->fixture->instance->id, 'name' => 'horizon',
            'runtime' => 'systemd', 'runtime_config' => ['command' => ['/usr/bin/php', 'artisan', 'horizon']],
            'restart_policy' => 'always', 'desired_state' => 'running', 'status' => 'active',
        ]);
        $this->fixture->deployment->current = 'fresh';
        $id = start_rollback($this)->json('data.id');
        run_operation_jobs();

        $operation = Operation::query()->findOrFail($id);
        $restart = $operation->steps()->where('name', 'process_restart')->sole();
        $deployment = InstanceDeployment::query()->sole();

        expect($runtime->restarted)->toBe([$process->id])
            ->and($this->fixture->deployment->current)->toBe('initial')
            ->and($operation->status)->toBe($fails ? OperationStatus::Failed : OperationStatus::Succeeded)
            ->and($operation->failed_step)->toBe($fails ? 'process_restart' : null)
            ->and($operation->error_code)->toBe($fails ? 'process.restart_failed' : null)
            ->and($operation->result['selected_release'] ?? null)->toBe('initial')
            ->and($restart->output)->toBe($fails
                ? "Failed to restart Process {$process->id}: process.restart_failed.\n"
                : "Restarted Process {$process->id}.\n")
            ->and($deployment->status)->toBe($fails ? 'failed' : 'succeeded')
            ->and($deployment->failed_step)->toBe($fails ? 'after_activation' : null)
            ->and($deployment->error_code)->toBe($fails ? 'process.restart_failed' : null)
            ->and(collect($deployment->events)->where('step', 'process_restart'))->toHaveCount(1);
    })->with([false, true]);
});

describe('cancelling a rollback', function (): void {
    it('stops before activation when the cancel arrives while it finds the release', function (): void {
        $id = start_rollback($this)->json('data.id');
        $this->fixture->deployment->current = 'fresh';
        $this->fixture->deployment->duringRetained = static function () use ($id): void {
            app(CancelOperationAction::class)->execute(Operation::query()->findOrFail($id));
        };
        run_operation_jobs();

        $operation = Operation::query()->sole();
        $steps = $operation->steps()->get()->keyBy('name');

        expect($operation->status)->toBe(OperationStatus::Cancelled)
            ->and($steps['rollback']->status)->toBe(OperationStepStatus::Succeeded)
            ->and($steps['activation']->status)->toBe(OperationStepStatus::Cancelled)
            ->and($steps['process_restart']->status)->toBe(OperationStepStatus::Cancelled)
            ->and($this->fixture->deployment->activations)->toBe(0)
            ->and($this->fixture->deployment->current)->toBe('fresh')
            ->and(InstanceDeployment::query()->sole()->error_code)->toBe('deployment.cancelled');
    });

    it('finishes PHP refresh and Process restarts when the cancel arrives during activation', function (): void {
        $runtime = new ProcessesApiFakeRuntimeManager;
        app()->instance(ProcessRuntimeManager::class, $runtime);
        $process = Process::query()->create([
            'owner_type' => $this->fixture->instance->getMorphClass(), 'owner_id' => $this->fixture->instance->id, 'name' => 'horizon',
            'runtime' => 'systemd', 'runtime_config' => ['command' => ['/usr/bin/php', 'artisan', 'horizon']],
            'restart_policy' => 'always', 'desired_state' => 'running', 'status' => 'active',
        ]);
        $this->fixture->deployment->current = 'fresh';
        $id = start_rollback($this)->json('data.id');
        $this->fixture->deployment->duringActivation = static function () use ($id): void {
            app(CancelOperationAction::class)->execute(Operation::query()->findOrFail($id));
        };
        run_operation_jobs();

        $operation = Operation::query()->sole();

        expect($operation->status)->toBe(OperationStatus::Succeeded)
            ->and($operation->cancel_requested_at)->not->toBeNull()
            ->and($operation->steps()->pluck('status', 'name')->map(static fn (OperationStepStatus $status): string => $status->value)->all())
            ->toBe(['rollback' => 'succeeded', 'activation' => 'succeeded', 'php_refresh' => 'succeeded', 'process_restart' => 'succeeded'])
            ->and($runtime->restarted)->toBe([$process->id])
            ->and($this->fixture->deployment->current)->toBe('initial')
            ->and(InstanceDeployment::query()->sole()->status)->toBe('succeeded');
    });
});
