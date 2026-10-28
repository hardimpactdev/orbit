<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Infrastructure\Instances\NativeInstanceEnvironmentOperationLock;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\InstanceDeployment;
use App\Models\InstanceEnvironmentValue;
use App\Models\Operation;
use App\Models\Task;
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
    $this->fixture->instance->project->update(['task_check' => 'composer check']);
    $this->failureKey = $this->rollback['failure_key'];
    $this->repairKey = $this->failureKey.'.repair';
    app(TaskExtensionState::class)->enable();
    $this->directory = sys_get_temp_dir().'/orbit-smoke-repair-'.bin2hex(random_bytes(8));
    app()->instance(InstanceEnvironmentOperationLock::class, new NativeInstanceEnvironmentOperationLock($this->directory, app(CommandDeadline::class)));
});
afterEach(function (): void {
    File::deleteDirectory($this->directory);
});

it('creates one default-agent external repair and replays it after recovery and task completion', function (): void {
    config()->set('orbit.tasks.implementer_model', 'configured-default-agent');
    $this->withHeader('Idempotency-Key', $this->failureKey)->postJson($this->url.'/failure', $this->failure)->assertOk();
    $this->getJson($this->url.'/repair/receipt?request_key='.$this->repairKey)->assertOk()->assertJsonPath('data.found', false);
    $repair = $this->withHeader('Idempotency-Key', $this->repairKey)->postJson($this->url.'/repair', ['failure_key' => $this->failureKey])->assertCreated()->json('data');
    $group = Task::topLevel()->sole();
    expect($repair['group_id'])->toBe($group->id);
    expect($repair['task_id'])->toBe($group->tasks->sole()->id);
    expect($group->implementer_model)->toBe('configured-default-agent');
    expect($group->orchestration->value)->toBe('external');
    expect($group->status)->toBe(TaskGroupStatus::Todo);
    expect($group->tasks->sole()->deliverables)->toHaveCount(2);
    expect($group->brief)->toContain($this->failure['commit'], $this->failure['release'], 'missing repo-owned Pest', 'migrations compatible', 'Source PR lookup unavailable', 'CI context unavailable');
    $group->update(['status' => TaskGroupStatus::Completed]);
    $this->fixture->deployment->current = 'fresh';
    app(TaskExtensionState::class)->disable();
    $this->postJson($this->url.'/repair', ['failure_key' => $this->failureKey])->assertCreated()->assertJsonPath('data', $repair);
    $this->getJson($this->url.'/repair/receipt?request_key='.$this->repairKey)->assertOk()->assertJsonPath('data.repair', $repair);
    expect(Task::topLevel()->count())->toBe(1);
    expect($this->fixture->instance->refresh()->auto_deploy_state['blocked'])->toContain($this->failure['commit']);
    expect($this->fixture->instance->auto_deploy_resumed_after)->toBe(0);
    expect(Operation::query()->where('kind', 'instance.rollback')->count())->toBe(0);
});

it('creates a repair despite a refused or failed rollback and keeps the original smoke hold', function (bool $admitted): void {
    $this->withHeader('Idempotency-Key', $this->failureKey)->postJson($this->url.'/failure', $this->failure)->assertOk();
    if ($admitted) {
        $this->withHeader('Idempotency-Key', $this->failureKey.'.rollback')->postJson($this->url.'/rollback', $this->rollback)->assertAccepted();
        Operation::query()->where('kind', 'instance.rollback')->sole()->update(['status' => 'failed', 'error_code' => 'fixture.rollback_failed', 'lock_key' => null]);
    } else {
        $this->fixture->deployment->retainedReleases = ['initial'];
    }
    $this->withHeader('Idempotency-Key', 'hold')->postJson($this->url.'/control', ['action' => 'hold', 'hold_key' => 'repair-hold',
        'expected' => $this->rollback['expected'], 'smoke_request_key' => null, 'rollback_request_key' => null])->assertOk();
    $this->withHeader('Idempotency-Key', $this->repairKey)->postJson($this->url.'/repair', ['failure_key' => $this->failureKey])->assertCreated();
    expect(Task::topLevel()->sole()->brief)->toContain('Recovery receipt:', $this->failureKey.'.rollback');
    expect($this->fixture->instance->refresh()->auto_deploy_state['smoke_control']['hold'])->not->toBeNull();
})->with([false, true]);

it('rejects absent or unverified failure evidence without creating a task', function (string $change): void {
    if ($change !== 'missing') {
        $this->withHeader('Idempotency-Key', $this->failureKey)->postJson($this->url.'/failure', $this->failure)->assertOk();
    }
    $op = Operation::query()->where('idempotency_key', 'proof.failed')->sole();
    $state = $op->state;
    match ($change) {
        'unknown', 'pending' => $state['smoke']['state'] = $change,
        'empty' => $state['smoke']['tests'] = 0,
        'clean' => $state['smoke']['failures'] = 0,
        'cancelled' => $op->status = 'cancelled',
        'private' => $this->fixture->instance->routes()->first()->update(['publication' => 'private']),
        'repository' => $this->fixture->instance->project->update(['repository_url' => 'https://github.com/acme/other.git']),
        default => null,
    };
    $op->update(['state' => $state]);
    $this->withHeader('Idempotency-Key', $this->repairKey)->postJson($this->url.'/repair', ['failure_key' => $this->failureKey])->assertUnprocessable();
    expect(Task::query()->count())->toBe(0);
})->with(['missing', 'unknown', 'pending', 'empty', 'clean', 'cancelled', 'private', 'repository']);

it('rolls back group, child and receipt together on a failed commit while holding the writer lock', function (): void {
    $this->withHeader('Idempotency-Key', $this->failureKey)->postJson($this->url.'/failure', $this->failure)->assertOk();
    $abort = true;
    DB::listen(function (QueryExecuted $query) use (&$abort): void {
        if (! str_contains($query->sql, 'update "instances" set "auto_deploy_state"') || ! str_contains($query->bindings[0], 'smoke_repairs')) {
            return;
        }
        expect(DB::transactionLevel())->toBeGreaterThan(0);
        $file = fopen($this->directory.'/app-instance-'.$this->fixture->instance->id.'.lock', 'c+');
        expect(flock($file, LOCK_EX | LOCK_NB))->toBeFalse();
        fclose($file);
        if ($abort) {
            $abort = false;
            throw new ResourceOperationException('fixture.abort', 'Injected commit failure.');
        }
    });
    $this->withHeader('Idempotency-Key', $this->repairKey)->postJson($this->url.'/repair', ['failure_key' => $this->failureKey])->assertUnprocessable();
    expect(Task::query()->count())->toBe(0);
    $this->getJson($this->url.'/repair/receipt?request_key='.$this->repairKey)->assertOk()->assertJsonPath('data.found', false);
    $this->postJson($this->url.'/repair', ['failure_key' => $this->failureKey])->assertCreated();
    expect(Task::topLevel()->count())->toBe(1);
});

it('uses a new incident for another failed deployment even for the same SHA', function (): void {
    $this->withHeader('Idempotency-Key', $this->failureKey)->postJson($this->url.'/failure', $this->failure)->assertOk();
    $this->withHeader('Idempotency-Key', $this->repairKey)->postJson($this->url.'/repair', ['failure_key' => $this->failureKey])->assertCreated();
    $row = InstanceDeployment::query()->findOrFail($this->failure['deployment_id'])->replicate();
    $row->save();
    $op = Operation::query()->where('idempotency_key', 'proof.failed')->sole()->replicate();
    $input = $op->input;
    $state = $op->state;
    $input['deployment_id'] = $state['smoke']['deployment_id'] = $row->id;
    $op->fill(['idempotency_key' => 'proof.repeated', 'input' => $input, 'state' => $state])->save();
    $key = 'smoke-failure.'.$this->fixture->instance->id.'.'.$row->id;
    $failure = [...$this->failure, 'deployment_id' => $row->id, 'smoke_request_key' => 'proof.repeated'];
    $this->withHeader('Idempotency-Key', $key)->postJson($this->url.'/failure', $failure)->assertOk();
    $this->withHeader('Idempotency-Key', $key.'.repair')->postJson($this->url.'/repair', ['failure_key' => $key])->assertCreated();
    expect(Task::topLevel()->count())->toBe(2);
});

it('bounds and redacts smoke and CI evidence while preserving source and run links', function (): void {
    $this->withHeader('Idempotency-Key', $this->failureKey)->postJson($this->url.'/failure', $this->failure)->assertOk();
    InstanceEnvironmentValue::query()->create(['instance_id' => $this->fixture->instance->id, 'env_key' => 'APP_KEY', 'env_value' => 'fixture-"private"-value']);
    $op = Operation::query()->where('idempotency_key', 'proof.failed')->sole();
    $state = $op->state;
    $state['smoke']['output'] = str_repeat('fixture-"private"-value render failed. ', 1500);
    $op->update(['state' => $state]);
    app(GitHubAppStore::class)->put(new GitHubAppCredentials(1, 'fixture', 'Fixture', 'acme', 'Organization', 'https://github.com/apps/fixture', 'fixture-key'));
    $github = Mockery::mock(GitHubApi::class);
    $github->shouldReceive('repositoryInstallation')->andReturn(1);
    $github->shouldReceive('repositoryPullRequestReadToken')->andReturn('fixture-pr-read');
    $github->shouldReceive('repositoryChecksToken')->andReturn('fixture-checks-read');
    $github->shouldReceive('mergedPullRequestUrls')->once()->andReturn(['https://github.com/acme/widgets/pull/12']);
    $github->shouldReceive('checkRuns')->once()->andReturn([new GitHubCheckRun('CI', 'success', 'https://github.com/acme/widgets/actions/runs/44',
        headSha: $this->failure['commit'], outputSummary: str_repeat('fixture-"private"-value CI passed. ', 500))]);
    app()->instance(GitHubApi::class, $github);
    $this->withHeader('Idempotency-Key', $this->repairKey)->postJson($this->url.'/repair', ['failure_key' => $this->failureKey])->assertCreated();
    $brief = Task::topLevel()->sole()->brief;
    expect(strlen($brief))->toBeLessThanOrEqual(8000);
    expect($brief)->not->toContain('fixture-"private"-value');
    expect($brief)->toContain('[REDACTED]', 'https://github.com/acme/widgets/pull/12', 'https://github.com/acme/widgets/actions/runs/44', 'diagnostic data');
});

it('enforces peer access, strict input, caller identity and Tasks availability', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.250'])->postJson($this->url.'/repair', ['failure_key' => $this->failureKey])->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip]);
    $this->withHeader('Idempotency-Key', $this->failureKey)->postJson($this->url.'/failure', $this->failure)->assertOk();
    $this->withHeader('Idempotency-Key', 'wrong')->postJson($this->url.'/repair', ['failure_key' => $this->failureKey])->assertUnprocessable();
    $this->withHeader('Idempotency-Key', $this->repairKey)->postJson($this->url.'/repair', ['failure_key' => $this->failureKey, 'brief' => 'override'])->assertUnprocessable();
    app(TaskExtensionState::class)->disable();
    $this->postJson($this->url.'/repair', ['failure_key' => $this->failureKey])->assertConflict()->assertJsonPath('error.code', 'extension.disabled');
    app(TaskExtensionState::class)->enable();
    $repair = $this->postJson($this->url.'/repair', ['failure_key' => $this->failureKey])->assertCreated()->json('data');
    $this->fixture->owner->accessibleNodes()->attach($this->fixture->caller->id);
    $this->withServerVariables(['REMOTE_ADDR' => $this->fixture->owner->wireguard_ip])->postJson($this->url.'/repair', ['failure_key' => $this->failureKey])->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');
    $this->getJson($this->url.'/repair/receipt?request_key='.$this->repairKey)->assertConflict();
    expect(Task::topLevel()->sole()->id)->toBe($repair['group_id']);
});

it('creates and looks up the same repair through generated MCP including its required key', function (): void {
    $this->withHeader('Idempotency-Key', $this->failureKey)->postJson($this->url.'/failure', $this->failure)->assertOk();
    $this->flushHeaders();
    $call = function (string $name, array $arguments): array {
        $response = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]])
            ->assertOk()->assertJsonPath('result.isError', false);

        return json_decode($response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR)['data'];
    };
    $repair = $call('instance-smoke-repair', ['instance' => $this->fixture->instance->id, 'Idempotency-Key' => $this->repairKey, 'failure_key' => $this->failureKey]);
    $lookup = $call('instance-smoke-repair-receipt', ['instance' => $this->fixture->instance->id, 'request_key' => $this->repairKey]);
    expect($lookup['found'])->toBeTrue();
    expect($lookup['repair'])->toBe($repair);
});

it('still creates a repair when the Project has no configured local check', function (): void {
    $this->fixture->instance->project->update(['task_check' => null]);
    $this->withHeader('Idempotency-Key', $this->failureKey)->postJson($this->url.'/failure', $this->failure)->assertOk();
    $this->withHeader('Idempotency-Key', $this->repairKey)->postJson($this->url.'/repair', ['failure_key' => $this->failureKey])->assertCreated();
    expect(Task::topLevel()->sole()->tasks->sole()->deliverables)->toHaveCount(1);
});
