<?php

declare(strict_types=1);

use App\Actions\Operations\CancelOperationAction;
use App\Domain\Instances\Deployment\AutomaticDeployHistory;
use App\Domain\Instances\Deployment\AutomaticDeployState;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Operations\OperationRunner;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationSweeper;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Instances\NativeInstanceEnvironmentOperationLock;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\InstanceDeployment;
use App\Models\Operation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\ProductionSmokeRecoveryFixture;

beforeEach(function (): void {
    $this->freezeTime();
    enable_operation_queue();
    [$this->fixture, $this->failure, $this->rollback] = ProductionSmokeRecoveryFixture::create();
    $this->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip]);
    $this->url = '/api/v1/instances/'.$this->fixture->instance->id.'/smoke';
    $this->key = $this->rollback['failure_key'];
    $this->lockDirectory = sys_get_temp_dir().'/orbit-smoke-recovery-'.bin2hex(random_bytes(8));
    app()->instance(InstanceEnvironmentOperationLock::class, new NativeInstanceEnvironmentOperationLock($this->lockDirectory, app(CommandDeadline::class)));
});
afterEach(function (): void {
    File::deleteDirectory($this->lockDirectory);
});

it('records a historical verified failure once and never clears its block on resume or recent-block pruning', function (): void {
    $this->fixture->deployment->current = 'fresh';
    $this->fixture->deployment->retainedReleases = ['fresh'];
    InstanceDeployment::query()->whereKey($this->failure['deployment_id'])->delete();
    $this->getJson($this->url.'/failure/receipt?request_key='.$this->key)->assertOk()->assertJsonPath('data.found', false)->assertJsonPath('data.block', null);
    $original = $this->withHeader('Idempotency-Key', $this->key)->postJson($this->url.'/failure', $this->failure)
        ->assertOk()->assertJsonPath('data.blocked', true)->assertJsonPath('data.failure', $this->failure)->json('data');
    $this->postJson($this->url.'/failure', $this->failure)->assertOk()->assertJsonPath('data', $original);
    $this->postJson($this->url.'/failure', [...$this->failure, 'release' => 'other'])->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');
    $instance = $this->fixture->instance->refresh();
    $state = AutomaticDeployState::of($instance);
    for ($n = 1; $n <= 25; $n++) {
        $state->block(str_pad(dechex($n), 40, '0', STR_PAD_LEFT));
    }
    $state->save($instance);
    $this->patchJson('/api/v1/instances/'.$instance->id, ['auto_deploy' => true])->assertOk();
    $this->getJson('/api/v1/instances/'.$instance->id)->assertOk()->assertJsonFragment(['blocked' => $instance->refresh()->auto_deploy_state['blocked']]);
    expect($instance->auto_deploy_state['blocked'])->toContain($this->failure['commit']);
    expect($this->fixture->deployment->activations)->toBe(0);
    $this->getJson($this->url.'/failure/receipt?request_key='.$this->key)->assertOk()->assertJsonPath('data.block', $original);
});

it('commits the failure and block atomically while holding the real deploy writer lock', function (): void {
    $abort = true;
    DB::listen(function (QueryExecuted $query) use (&$abort): void {
        if (! str_contains($query->sql, 'update "instances" set "auto_deploy_state"')) {
            return;
        }
        expect(DB::transactionLevel())->toBeGreaterThan(0);
        $file = fopen($this->lockDirectory.'/app-instance-'.$this->fixture->instance->id.'.lock', 'c+');
        expect(flock($file, LOCK_EX | LOCK_NB))->toBeFalse();
        fclose($file);
        $state = json_decode($query->bindings[0], true, flags: JSON_THROW_ON_ERROR);
        expect($state['blocked'])->toContain($this->failure['commit']);
        expect($state['smoke_failures'])->toHaveKey($this->key);
        if ($abort) {
            $abort = false;
            throw new ResourceOperationException('fixture.atomic_abort', 'Injected abort.');
        }
    });
    $this->withHeader('Idempotency-Key', $this->key)->postJson($this->url.'/failure', $this->failure)->assertUnprocessable();
    expect($this->fixture->instance->refresh()->auto_deploy_state)->toBeNull();
    $this->getJson($this->url.'/failure/receipt?request_key='.$this->key)->assertOk()->assertJsonPath('data.found', false);
    $this->postJson($this->url.'/failure', $this->failure)->assertOk();
});

it('refuses unverified failures and ownership without any state mutation', function (string $change): void {
    $op = Operation::query()->where('idempotency_key', 'proof.failed')->sole();
    $state = $op->state;
    $input = $op->input;
    match ($change) {
        'unknown' => $state['smoke']['state'] = 'unknown',
        'empty' => $state['smoke']['tests'] = 0,
        'skipped' => $state['smoke']['skipped'] = 1,
        'clean' => $state['smoke']['failures'] = 0,
        'attempt' => $state['smoke']['run_attempt'] = null,
        'workflow' => $state['workflow']['sha'] = str_repeat('d', 40),
        'missing' => $op->idempotency_key = 'missing',
        'caller' => $input['caller_node_id'] = $this->fixture->owner->id,
        'repository' => $input['repository_url'] = 'https://github.com/acme/other.git',
        'private' => $this->fixture->instance->routes()->first()->update(['publication' => 'private']),
        'target' => $state['smoke']['release'] = 'other',
        'cancelled' => $op->status = OperationStatus::Cancelled,
    };
    $op->update(['state' => $state, 'input' => $input]);
    $this->withHeader('Idempotency-Key', $this->key)->postJson($this->url.'/failure', $this->failure)
        ->assertUnprocessable()->assertJsonPath('error.code', 'instance.smoke_failure_invalid');
    expect($this->fixture->instance->refresh()->auto_deploy_state)->toBeNull();
})->with(['unknown', 'empty', 'skipped', 'clean', 'attempt', 'workflow', 'missing', 'caller', 'repository', 'private', 'target', 'cancelled']);

it('runs the native rollback once and returns its new deployment without resuming automation', function (): void {
    $this->withHeader('Idempotency-Key', $this->key)->postJson($this->url.'/failure', $this->failure)->assertOk();
    $this->getJson($this->url.'/rollback/receipt?request_key='.$this->key.'.rollback')->assertOk()->assertJsonPath('data.found', false)->assertJsonPath('data.operation', null)->assertJsonPath('data.deployment', null);
    $this->withHeader('Idempotency-Key', $this->key.'.rollback')->postJson($this->url.'/rollback', $this->rollback)
        ->assertAccepted()->assertJsonPath('data.kind', 'instance.rollback')->assertJsonPath('data.target_type', 'instance');
    $op = Operation::query()->where('kind', 'instance.rollback')->sole();
    $this->postJson($this->url.'/rollback', $this->rollback)->assertAccepted()->assertJsonPath('data.id', $op->id);
    app(OperationRunner::class)->run($op->id);
    $this->postJson($this->url.'/rollback', $this->rollback)->assertAccepted()->assertJsonPath('data.id', $op->id);
    $receipt = $this->getJson($this->url.'/rollback/receipt?request_key='.$this->key.'.rollback')->assertOk()
        ->assertJsonPath('data.operation.status', 'succeeded')->assertJsonPath('data.deployment.commit', $this->rollback['target']['commit'])
        ->assertJsonPath('data.deployment.release', 'fresh')->assertJsonPath('data.deployment.status', 'succeeded');
    expect($receipt->json('data.deployment.deployment_id'))->not->toBe($this->rollback['target']['deployment_id']);
    expect($this->fixture->deployment->activations)->toBe(1);
    expect($this->fixture->instance->refresh()->auto_deploy_resumed_after)->toBe(0);
    expect(new AutomaticDeployHistory($this->fixture->instance->refresh())->pausedBy()?->kind)->toBe('rollback');
});

it('rechecks queued guards under the writer lock and never chooses another target', function (string $change): void {
    $this->withHeader('Idempotency-Key', $this->key)->postJson($this->url.'/failure', $this->failure)->assertOk();
    $this->withHeader('Idempotency-Key', $this->key.'.rollback')->postJson($this->url.'/rollback', $this->rollback)->assertAccepted();
    if ($change === 'selection') {
        $this->fixture->deployment->current = 'fresh';
    }
    if ($change === 'pruned') {
        $this->fixture->deployment->retainedReleases = ['initial'];
    }
    if ($change === 'sha') {
        $this->fixture->deployment->commits['fresh'] = str_repeat('d', 40);
    }
    if ($change === 'verification') {
        $this->fixture->deployment->duringActivation = function (): void {
            $this->fixture->deployment->current = 'initial';
        };
    }
    if ($change === 'failed') {
        $reads = 0;
        $this->fixture->deployment->duringRetained = function () use (&$reads): void {
            if (++$reads === 2) {
                $this->fixture->deployment->failRollback = true;
            }
        };
    }
    $this->fixture->deployment->duringSelection = function (): void {
        $file = fopen($this->lockDirectory.'/app-instance-'.$this->fixture->instance->id.'.lock', 'c+');
        try {
            expect(flock($file, LOCK_EX | LOCK_NB))->toBeFalse();
        } finally {
            fclose($file);
        }
    };
    $op = Operation::query()->where('kind', 'instance.rollback')->sole();
    app(OperationRunner::class)->run($op->id);
    $this->getJson($this->url.'/rollback/receipt?request_key='.$this->key.'.rollback')->assertOk()->assertJsonPath('data.operation.status', 'failed');
    expect($this->fixture->deployment->activations)->toBe($change === 'verification' ? 1 : 0);
    expect($this->fixture->instance->refresh()->auto_deploy_state['blocked'])->toContain($this->failure['commit']);
    if ($change !== 'failed') {
        expect($op->refresh()->error_code)->toBe(in_array($change, ['selection', 'verification'], true) ? 'instance.smoke_selection_changed' : 'instance.smoke_rollback_target_missing');
    }
    $this->postJson($this->url.'/rollback', $this->rollback)->assertAccepted()->assertJsonPath('data.id', $op->id);
})->with(['selection', 'pruned', 'sha', 'failed', 'verification']);

it('requires a block and a prior passing target and binds recovery keys to the caller and full body', function (): void {
    $this->withHeader('Idempotency-Key', $this->key.'.rollback')->postJson($this->url.'/rollback', $this->rollback)->assertUnprocessable();
    $this->withHeader('Idempotency-Key', $this->key)->postJson($this->url.'/failure', $this->failure)->assertOk();
    $this->withHeader('Idempotency-Key', $this->key.'.rollback')->postJson($this->url.'/rollback', [...$this->rollback, 'target_smoke_request_key' => 'proof.failed'])->assertUnprocessable();
    $passed = Operation::query()->where('idempotency_key', 'proof.passed')->sole();
    $state = $passed->state;
    $passed->update(['state' => [...$state, 'smoke' => [...$state['smoke'], 'skipped' => 1]]]);
    $this->postJson($this->url.'/rollback', $this->rollback)->assertUnprocessable();
    $passed->update(['state' => $state]);
    $this->postJson($this->url.'/rollback', $this->rollback)->assertAccepted();
    $changed = $this->rollback;
    $changed['target']['commit'] = str_repeat('e', 40);
    $this->postJson($this->url.'/rollback', $changed)->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');
    $this->fixture->owner->accessibleNodes()->attach($this->fixture->caller->id);
    $this->withServerVariables(['REMOTE_ADDR' => $this->fixture->owner->wireguard_ip]);
    $this->postJson($this->url.'/rollback', $this->rollback)->assertConflict();
    $this->getJson($this->url.'/failure/receipt?request_key='.$this->key)->assertConflict();
    $this->getJson($this->url.'/rollback/receipt?request_key='.$this->key.'.rollback')->assertConflict();
});

it('refuses a rollback target that is not a distinct prior deployment', function (string $change): void {
    $this->withHeader('Idempotency-Key', $this->key)->postJson($this->url.'/failure', $this->failure)->assertOk();
    $target = &$this->rollback['target'];
    match ($change) {
        'commit' => $target['commit'] = $this->failure['commit'],
        'release' => $target['release'] = $this->failure['release'],
        'same' => $target['deployment_id'] = $this->failure['deployment_id'],
        'later' => $target['deployment_id'] = $this->failure['deployment_id'] + 1,
    };
    $passed = Operation::query()->where('idempotency_key', 'proof.passed')->sole();
    $passed->update(['input' => [...$passed->input, ...$target],
        'state' => [...$passed->state, 'smoke' => [...$passed->state['smoke'], ...$target]]]);
    $this->withHeader('Idempotency-Key', $this->key.'.rollback')->postJson($this->url.'/rollback', $this->rollback)
        ->assertUnprocessable()->assertJsonPath('error.code', 'instance.smoke_rollback_invalid');
    expect(Operation::query()->where('kind', 'instance.rollback')->exists())->toBeFalse();
})->with(['commit', 'release', 'same', 'later']);

it('keeps blocks visible while automation is disabled and keeps cancelled receipts inert', function (): void {
    $this->withHeader('Idempotency-Key', $this->key)->postJson($this->url.'/failure', $this->failure)->assertOk();
    $this->patchJson('/api/v1/instances/'.$this->fixture->instance->id, ['auto_deploy' => false])->assertOk();
    $this->getJson('/api/v1/instances/'.$this->fixture->instance->id)->assertOk()
        ->assertJsonPath('data.auto_deploy.blocked', [$this->failure['commit']])->assertJsonPath('data.auto_deploy.history_version', null);
    $this->withHeader('Idempotency-Key', $this->key.'.rollback')->postJson($this->url.'/rollback', $this->rollback)->assertAccepted();
    $op = Operation::query()->where('kind', 'instance.rollback')->sole();
    app(CancelOperationAction::class)->execute($op);
    $this->getJson($this->url.'/rollback/receipt?request_key='.$this->key.'.rollback')->assertOk()
        ->assertJsonPath('data.operation.status', 'cancelled')->assertJsonPath('data.deployment', null);
    $this->postJson($this->url.'/rollback', $this->rollback)->assertAccepted()->assertJsonPath('data.id', $op->id);
    expect($this->fixture->deployment->activations)->toBe(0);
    expect($this->fixture->instance->refresh()->auto_deploy_state['blocked'])->toContain($this->failure['commit']);
});

it('refuses receipts against another Instance', function (): void {
    $this->withHeader('Idempotency-Key', $this->key)->postJson($this->url.'/failure', $this->failure)->assertOk();
    $this->withHeader('Idempotency-Key', $this->key.'.rollback')->postJson($this->url.'/rollback', $this->rollback)->assertAccepted();
    $project = $this->fixture->instance->project->replicate();
    $project->slug .= '-other';
    $project->code = 'OTH';
    $project->save();
    $other = $this->fixture->instance->replicate();
    $other->project_id = $project->id;
    $other->checkout_path .= '-other';
    $other->save();
    $url = '/api/v1/instances/'.$other->id.'/smoke';
    $this->getJson($url.'/failure/receipt?request_key='.$this->key)->assertConflict();
    $this->getJson($url.'/rollback/receipt?request_key='.$this->key.'.rollback')->assertConflict();
});

it('resumes after activation without selecting the release twice', function (): void {
    $this->withHeader('Idempotency-Key', $this->key)->postJson($this->url.'/failure', $this->failure)->assertOk();
    $this->withHeader('Idempotency-Key', $this->key.'.rollback')->postJson($this->url.'/rollback', $this->rollback)->assertAccepted();
    $op = Operation::query()->where('kind', 'instance.rollback')->sole();
    ProductionSmokeRecoveryFixture::activated($op, $this->fixture);
    app(OperationSweeper::class)->sweep();
    app(OperationRunner::class)->run($op->id);
    $this->getJson($this->url.'/rollback/receipt?request_key='.$this->key.'.rollback')->assertOk()
        ->assertJsonPath('data.operation.status', 'succeeded')->assertJsonPath('data.deployment.release', 'fresh');
    expect($this->fixture->deployment->activations)->toBe(0);
});
