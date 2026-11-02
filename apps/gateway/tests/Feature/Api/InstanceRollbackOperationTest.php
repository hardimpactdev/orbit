<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceRollbackAction;
use App\Domain\Instances\Deployment\AutomaticDeployHistory;
use App\Domain\Instances\Deployment\AutomaticDeployState;
use App\Domain\Instances\Deployment\InstanceRollbackProjection;
use App\Domain\Instances\Deployment\RecordedReleases;
use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Route;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->socket = $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started',
        'workflowName' => $request['payload']['workflowIdOrName'], 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'synthetic-shared-run-token']);
});

/** A production Instance on `r2`, with `r1` retained from the deploy before it. */
function rollbackInstance(bool $php = true): Instance
{
    $instance = dev935_instance('rollback', role: 'app-prod');
    $home = '/home/orbit-app-'.$instance->project_id;
    $instance->node->update(['architecture' => 'x86_64']);
    $instance->project->update(['root' => 'public']);
    $instance->update(['name' => 'production', 'environment' => 'production', 'source_layout' => 'release', 'production_user' => 'orbit-app-'.$instance->project_id,
        'production_home' => $home, 'checkout_path' => $home.'/releases/r2', 'root' => 'public', 'branch_override' => 'main',
        'source_is_laravel' => true, 'provisioning_step' => 'active', 'selected_php_version' => $php ? '8.5' : null]);
    if ($php) {
        $instance->update(ProductionPhpRuntimeIdentity::forProvisioning($instance, '8.5')->attributes());
    }
    foreach (['r1' => 'a', 'r2' => 'b'] as $release => $commit) {
        InstanceDeployment::query()->create(['instance_id' => $instance->id, 'kind' => 'deploy', 'branch' => 'main', 'release' => $release,
            'selected_release' => $release, 'commit' => str_repeat($commit, 40), 'status' => 'succeeded', 'started_at' => now(), 'finished_at' => now(),
            'triggered_by' => AutomaticDeployHistory::SwampTrigger]);
    }

    return $instance->refresh();
}

function startRollback(Instance $instance, string $release = 'r1'): Operation
{
    return app(StartInstanceRollbackAction::class)->execute($instance, $release, 'operator', null);
}

/** Applies a native terminal: each named step's status, as Swamp's run data gives them. */
function applyRollback(Operation $operation, string $status, array $steps): Operation
{
    $failed = array_keys(array_filter($steps, static fn (string $step): bool => $step === 'failed'));

    return app(InstanceRollbackProjection::class)->apply($operation->refresh(), ['run_id' => $operation->state['swamp_run_id'], 'status' => $status,
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'error_code' => $status === 'failed' ? 'swamp.step_failed' : null,
        'result' => ['steps' => array_map(static fn (string $step): array => ['status' => $step], $steps),
            'summary' => ['status' => $status, 'succeeded' => 0, 'failed' => count($failed), 'skipped' => 0, 'failed_steps' => $failed]]]);
}

describe('admission', function (): void {
    it('holds the Instance, freezes the full intent, starts the native run and pauses automatic deployment at once', function (): void {
        $instance = rollbackInstance();
        $process = Process::query()->create(['owner_type' => $instance->getMorphClass(), 'owner_id' => $instance->id, 'name' => 'horizon',
            'runtime' => 'systemd', 'runtime_config' => ['command' => ['/usr/bin/php', 'artisan', 'horizon']],
            'restart_policy' => 'always', 'restart_on_deploy' => true, 'desired_state' => 'running', 'status' => 'active']);
        Process::query()->create(['owner_type' => $instance->getMorphClass(), 'owner_id' => $instance->id, 'name' => 'stays',
            'runtime' => 'systemd', 'runtime_config' => ['command' => ['/usr/bin/sleep', '30']],
            'restart_policy' => 'always', 'restart_on_deploy' => false, 'desired_state' => 'running', 'status' => 'active']);

        $operation = startRollback($instance);
        $row = InstanceDeployment::query()->where('kind', InstanceDeployment::Rollback)->sole();

        expect($operation->kind)->toBe('instance.rollback')
            ->and($operation->lock_key)->toBe("instance:{$instance->id}")
            ->and($operation->input['release'])->toBe('r1')
            ->and($operation->input['commit'])->toBe(str_repeat('a', 40))
            ->and($operation->input['php_input'])->toBe(['service' => ProductionPhpRuntimeIdentity::from($instance)->service])
            ->and($operation->input['runtime_definitions'])->toBe(['restart' => [
                ['process_id' => $process->id, 'runtime' => 'systemd', 'name' => "orbit-process-{$process->id}-horizon.service"]]])
            ->and($operation->fresh()->state['deployment_id'])->toBe($row->id)
            ->and($row->status)->toBe('running')
            ->and($row->triggered_by)->toBe('operator')
            ->and(new AutomaticDeployHistory($instance->fresh())->pausedBy()?->id)->toBe($row->id);

        $run = collect($this->socket->sent)->last()['payload'];
        $home = $instance->production_home;
        expect($run['workflowIdOrName'])->toBe('operation-instance-rollback')
            ->and($run['inputs']['intent']['rollback'])->toBe(['instance_id' => $instance->id, 'release' => 'r1', 'user' => $instance->production_user,
                'home' => $home, 'links' => [['path' => "{$home}/current.next", 'target' => 'releases/r1']]])
            ->and($run['inputs']['intent']['php_input'])->toBe($operation->input['php_input'])
            ->and($run['inputs']['intent']['ssh_execution']['target']['host'])->toBe($instance->node->wireguard_ip);
    });

    it('links each served application .env in the release before current', function (): void {
        $instance = rollbackInstance();
        $cluster = Cluster::query()->create(['name' => 'rollback', 'state' => 'active']);
        $route = Route::query()->create(['project_id' => $instance->project_id, 'cluster_id' => $cluster->id, 'domain' => 'admin.test', 'web_root' => 'apps/admin/public',
            'status' => 'pending', 'publication' => 'private', 'provenance' => 'explicit']);
        $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
        $route->update(['status' => 'active']);

        startRollback($instance);

        $home = $instance->production_home;
        expect(collect($this->socket->sent)->last()['payload']['inputs']['intent']['rollback']['links'])->toBe([
            ['path' => "{$home}/releases/r1/apps/admin/.env", 'target' => '../../../../env/apps/admin/.env'],
            ['path' => "{$home}/current.next", 'target' => 'releases/r1']]);
    });

    it('sends no PHP refresh for an Instance that serves no PHP', function (): void {
        expect(startRollback(rollbackInstance(php: false))->input['php_input'])->toBeNull();
    });

    it('refuses a release the history does not retain and records nothing', function (): void {
        $instance = rollbackInstance();

        expect(fn () => startRollback($instance, 'gone'))->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('rollback.release_not_retained')
            ->and($error->status)->toBe(422));
        expect(Operation::query()->count())->toBe(0)
            ->and(InstanceDeployment::query()->where('kind', InstanceDeployment::Rollback)->count())->toBe(0)
            ->and(new AutomaticDeployHistory($instance->fresh())->pausedBy())->toBeNull();
    });

    it('refuses a development Instance', function (): void {
        expect(fn () => startRollback(dev935_instance()))->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('deployment_config.unavailable'));
    });

    it('refuses a second rollback while the first holds the Instance', function (): void {
        $instance = rollbackInstance();
        startRollback($instance);

        expect(fn () => startRollback($instance))->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.target_busy'));
    });
});

describe('the native outcome', function (): void {
    it('records the rollback, selects its release and blocks the commit it moved away from', function (): void {
        $instance = rollbackInstance();
        $operation = startRollback($instance);

        $projected = applyRollback($operation, 'succeeded', ['Activate release-0' => 'succeeded', 'Swap current' => 'succeeded', 'Refresh PHP cache' => 'succeeded',
            'Restart Processes-0' => 'succeeded', 'Read current back' => 'succeeded', 'Check the selection' => 'succeeded']);
        $row = InstanceDeployment::query()->where('kind', InstanceDeployment::Rollback)->sole();

        expect($projected->status)->toBe(OperationStatus::Succeeded)
            ->and($projected->lock_key)->toBeNull()
            ->and($projected->result)->toMatchArray(['deployment_id' => $row->id, 'release' => 'r1', 'selected_release' => 'r1', 'commit' => str_repeat('a', 40)])
            ->and($row->status)->toBe('succeeded')
            ->and($row->selected_release)->toBe('r1')
            ->and($row->commit)->toBe(str_repeat('a', 40))
            ->and($instance->fresh()->checkout_path)->toBe($instance->production_home.'/releases/r1')
            ->and(AutomaticDeployState::of($instance->fresh())->blocked())->toBe([str_repeat('b', 40)])
            ->and(new AutomaticDeployHistory($instance->fresh())->pausedBy()?->id)->toBe($row->id);
    });

    it('keeps the rollback release selected when a Process restart fails', function (): void {
        $instance = rollbackInstance();
        $operation = startRollback($instance);

        $projected = applyRollback($operation, 'failed', ['Activate release-0' => 'succeeded', 'Swap current' => 'succeeded', 'Refresh PHP cache' => 'succeeded',
            'Restart Processes-0' => 'failed', 'Read current back' => 'succeeded', 'Check the selection' => 'succeeded']);
        $row = InstanceDeployment::query()->where('kind', InstanceDeployment::Rollback)->sole();

        expect($projected->status)->toBe(OperationStatus::Failed)
            ->and($projected->error_code)->toBe('process.restart_failed')
            ->and($row->status)->toBe('failed')
            ->and($row->failed_step)->toBe('Restart Processes')
            ->and($row->selected_release)->toBe('r1')
            ->and($instance->fresh()->checkout_path)->toBe($instance->production_home.'/releases/r1')
            ->and(AutomaticDeployState::of($instance->fresh())->blocked())->toBe([str_repeat('b', 40)]);
    });

    it('keeps the old selection on record when activation fails', function (): void {
        $instance = rollbackInstance();
        $operation = startRollback($instance);

        // A served .env link failed, so `current.next` was never written and `current` never moved.
        $projected = applyRollback($operation, 'failed', ['Activate release-0' => 'failed', 'Activate release-1' => 'skipped',
            'Swap current' => 'skipped', 'Refresh PHP cache' => 'skipped', 'Check the selection' => 'skipped']);
        $row = InstanceDeployment::query()->where('kind', InstanceDeployment::Rollback)->sole();

        expect($projected->error_code)->toBe('rollback.release_invalid')
            ->and($row->failed_step)->toBe('Activate release')
            ->and($row->selected_release)->toBeNull()
            ->and($instance->fresh()->checkout_path)->toBe($instance->production_home.'/releases/r2')
            ->and(new AutomaticDeployHistory($instance->fresh())->pausedBy()?->id)->toBe($row->id);
    });

    it('keeps the old selection on record when the rename over current fails', function (): void {
        $instance = rollbackInstance();
        $operation = startRollback($instance);

        $projected = applyRollback($operation, 'failed', ['Activate release-0' => 'succeeded', 'Swap current' => 'failed',
            'Refresh PHP cache' => 'skipped', 'Check the selection' => 'skipped']);
        $row = InstanceDeployment::query()->where('kind', InstanceDeployment::Rollback)->sole();

        expect($projected->error_code)->toBe('deployment.selection_invalid')
            ->and($row->failed_step)->toBe('Swap current')
            ->and($row->selected_release)->toBeNull()
            ->and($instance->fresh()->checkout_path)->toBe($instance->production_home.'/releases/r2');
    });

    it('fails the running row when the run budget expires', function (): void {
        $instance = rollbackInstance();
        $operation = startRollback($instance);

        app(InstanceRollbackProjection::class)->timedOut($operation->fresh());

        expect(InstanceDeployment::query()->where('kind', InstanceDeployment::Rollback)->sole()->error_code)->toBe('operation.timed_out');
    });
});

describe('recorded releases', function (): void {
    it('keeps the new selection, the one before it and the newest other release after each successful deploy', function (): void {
        $instance = rollbackInstance();
        foreach (['r3', 'r4'] as $release) {
            InstanceDeployment::query()->create(['instance_id' => $instance->id, 'kind' => 'deploy', 'branch' => 'main', 'release' => $release,
                'selected_release' => $release, 'commit' => null, 'status' => 'succeeded', 'started_at' => now()]);
        }

        expect(array_keys(RecordedReleases::of($instance)))->toEqualCanonicalizing(['r2', 'r3', 'r4']);
    });

    it('keeps a failed release and moves no selection on a rollback the cleanup must respect', function (): void {
        $instance = rollbackInstance();
        InstanceDeployment::query()->create(['instance_id' => $instance->id, 'kind' => 'rollback', 'branch' => 'main', 'release' => 'r1',
            'selected_release' => 'r1', 'status' => 'succeeded', 'started_at' => now()]);
        InstanceDeployment::query()->create(['instance_id' => $instance->id, 'kind' => 'deploy', 'branch' => 'main', 'release' => 'r3',
            'status' => 'failed', 'started_at' => now()]);
        InstanceDeployment::query()->create(['instance_id' => $instance->id, 'kind' => 'deploy', 'branch' => 'main', 'release' => 'r4',
            'selected_release' => 'r4', 'status' => 'succeeded', 'started_at' => now()]);

        // r4 is new, r1 was selected before it, and r3 is the newest other release; r2 and initial go.
        expect(array_keys(RecordedReleases::of($instance)))->toEqualCanonicalizing(['r1', 'r3', 'r4']);
    });

    it('starts with the initial release of a clone', function (): void {
        $instance = dev935_instance('fresh', role: 'app-prod');

        expect(RecordedReleases::of($instance))->toBe(['initial' => null]);
    });
});
