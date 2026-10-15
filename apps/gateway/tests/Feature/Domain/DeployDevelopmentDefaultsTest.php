<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceDeployAction;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\Deployment\AutomaticDeployHistory;
use App\Domain\Instances\Deployment\DevelopmentDeployment;
use App\Domain\Instances\DevelopmentRouteProjector;
use App\Domain\Projects\DevelopmentDeployStep;
use App\Domain\SourceControl\RemoteBranchHeads;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Operation;
use App\Models\Route;
use Tests\Support\Dev935Deployment;
use Tests\Support\FakeRemoteBranchHeads;

beforeEach(function (): void {
    enable_operation_queue();
    $this->remote = new Dev935Deployment;
    app()->instance(DevelopmentDeployment::class, $this->remote);
    $this->heads = new FakeRemoteBranchHeads;
    app()->instance(RemoteBranchHeads::class, $this->heads);
    $this->instance = dev935_instance();
    $this->heads->set($this->instance->project, 'main', str_repeat('b', 40));
});

function dev_defaults_route(Instance $instance, string $domain): void
{
    $instance->update(['root' => 'public', 'selected_php_version' => '8.5', 'source_is_laravel' => true]);
    $route = Route::query()->create(['project_id' => $instance->project_id, 'node_id' => $instance->node_id, 'domain' => $domain, 'provenance' => 'explicit', 'publication' => 'public', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);
}

describe('development defaults on the automatic deployment tick', function (): void {
    it('starts no operation and writes nothing while the default runs the branch head', function (): void {
        $this->instance->update(['seed_commit' => str_repeat('b', 40)]);

        $this->artisan('orbit:auto-deploy')->assertSuccessful();

        expect(Operation::query()->count())->toBe(0)
            ->and(InstanceDeployment::query()->count())->toBe(0)
            ->and($this->remote->trace)->toBe([])
            ->and($this->heads->reads)->toBe(['dev935:main']);
    });

    it('starts a deployment pinned to the head and deploys exactly that commit', function (): void {
        $this->artisan('orbit:auto-deploy')->expectsOutputToContain('started')->assertSuccessful();
        $operation = Operation::query()->sole();

        expect($operation->kind)->toBe('instance.deploy')
            ->and($operation->input)->toBe(['commit' => str_repeat('b', 40)])
            ->and($operation->triggered_by)->toBe(AutomaticDeployHistory::Trigger)
            ->and(InstanceDeployment::query()->count())->toBe(0);

        // The branch moves on before the job runs; the deployment keeps the commit the tick saw.
        $this->remote->targetCommit = str_repeat('c', 40);
        run_operation_jobs();
        $row = InstanceDeployment::query()->sole();

        expect($row->commit)->toBe(str_repeat('b', 40))
            ->and($row->status)->toBe('succeeded')
            ->and($row->triggered_by)->toBe(AutomaticDeployHistory::Trigger)
            ->and($this->remote->trace)->toBe(['target:main@'.str_repeat('b', 40), 'checkout:'.str_repeat('b', 40)])
            ->and($this->instance->fresh()->seed_commit)->toBe(str_repeat('b', 40));

        $this->artisan('orbit:auto-deploy')->assertSuccessful();

        expect(Operation::query()->count())->toBe(1)
            ->and(InstanceDeployment::query()->count())->toBe(1);
    });

    it('coalesces pushes that arrive during a deployment into the head of the next tick', function (): void {
        $this->artisan('orbit:auto-deploy')->assertSuccessful();
        $this->heads->set($this->instance->project, 'main', str_repeat('c', 40));
        $this->heads->set($this->instance->project, 'main', str_repeat('d', 40));

        $this->artisan('orbit:auto-deploy')->expectsOutputToContain('busy')->assertSuccessful();
        run_operation_jobs();
        $this->artisan('orbit:auto-deploy')->assertSuccessful();
        run_operation_jobs();

        expect(InstanceDeployment::query()->orderBy('id')->pluck('commit')->all())->toBe([str_repeat('b', 40), str_repeat('d', 40)])
            ->and($this->instance->fresh()->seed_commit)->toBe(str_repeat('d', 40));
    });

    it('reports its own waiting deployment instead of starting another', function (): void {
        $this->artisan('orbit:auto-deploy')->assertSuccessful();

        $this->artisan('orbit:auto-deploy')->expectsOutputToContain('deploying')->assertSuccessful();

        expect(Operation::query()->count())->toBe(1);
    });

    it('skips an Instance that another operation holds', function (): void {
        $manual = app(StartInstanceDeployAction::class)->execute($this->instance, 'laptop', null);

        $this->artisan('orbit:auto-deploy')->expectsOutputToContain("busy bbbbbbbbbbbb operation {$manual->id}")->assertSuccessful();

        expect(Operation::query()->count())->toBe(1);
    });

    it('waits ten minutes before it deploys a failed commit again', function (): void {
        dev935_steps($this->instance, [new DevelopmentDeployStep('build', 'false')]);
        $this->remote->failedStep = 'build';
        $this->artisan('orbit:auto-deploy')->assertSuccessful();
        run_operation_jobs();

        $this->artisan('orbit:auto-deploy')->expectsOutputToContain('backing_off')->assertSuccessful();
        expect(Operation::query()->count())->toBe(1);

        $this->remote->failedStep = null;
        $this->travel(11)->minutes();
        $this->artisan('orbit:auto-deploy')->assertSuccessful();
        run_operation_jobs();

        expect(InstanceDeployment::query()->orderBy('id')->pluck('status')->all())->toBe(['failed', 'succeeded'])
            ->and($this->instance->fresh()->seed_commit)->toBe(str_repeat('b', 40));
    });

    it('stops deploying a commit after three failures, waiting longer each time, until a new commit', function (): void {
        dev935_steps($this->instance, [new DevelopmentDeployStep('build', 'false')]);
        $this->remote->failedStep = 'build';

        foreach ([0, 11, 21] as $minutes) {
            $this->travel($minutes)->minutes();
            $this->artisan('orbit:auto-deploy')->assertSuccessful();
            run_operation_jobs();
        }
        $this->travel(2)->hours();
        $this->artisan('orbit:auto-deploy')->expectsOutputToContain('commit_failed')->assertSuccessful();

        expect(InstanceDeployment::query()->count())->toBe(3);

        $this->heads->set($this->instance->project, 'main', str_repeat('c', 40));
        $this->artisan('orbit:auto-deploy')->expectsOutputToContain('started')->assertSuccessful();
    });

    it('deploys every app-dev default and leaves other Instances alone', function (): void {
        $otherDefault = dev935_instance('second-default', 'default');
        $this->heads->set($otherDefault->project, 'main', str_repeat('c', 40));
        dev935_instance('ordinary', 'task-935');
        dev935_instance('production', 'default', 'app-prod');

        $this->artisan('orbit:auto-deploy')->assertSuccessful();
        run_operation_jobs();

        expect(InstanceDeployment::query()->orderBy('instance_id')->pluck('instance_id')->all())->toBe([$this->instance->id, $otherDefault->id])
            ->and(InstanceDeployment::query()->pluck('triggered_by')->unique()->all())->toBe([AutomaticDeployHistory::Trigger])
            ->and($this->heads->reads)->toBe(['dev935:main', 'second-default:main']);
    });

    it('keeps its old command name', function (): void {
        $this->artisan('orbit:deploy-development-defaults')->assertSuccessful();

        expect(Operation::query()->count())->toBe(1);
    });

    it('fails the tick for a branch head it cannot read and still checks the other defaults', function (): void {
        $other = dev935_instance('readable', 'default');
        $this->heads->set($other->project, 'main', str_repeat('c', 40));
        $this->heads->heads = ['readable:main' => str_repeat('c', 40)];

        $this->artisan('orbit:auto-deploy')->expectsOutputToContain('source.head_unavailable')->assertFailed();

        expect(Operation::query()->sole()->target_id)->toBe($other->id);
    });

    it('fails the tick without the Gateway queue only when it has something to deploy', function (): void {
        config(['orbit.gateway_queue.connection' => '']);
        $this->instance->update(['seed_commit' => str_repeat('b', 40)]);
        $this->artisan('orbit:auto-deploy')->assertSuccessful();

        $this->heads->set($this->instance->project, 'main', str_repeat('c', 40));
        $this->artisan('orbit:auto-deploy')->expectsOutputToContain('operation.queue_unavailable')->assertFailed();

        expect(Operation::query()->count())->toBe(0);
    });

    it('repairs a pending Route projection on an unchanged tick without a deployment', function (): void {
        $this->instance->update(['seed_commit' => str_repeat('b', 40)]);
        dev_defaults_route($this->instance, 'recover935.example.test');
        $this->instance->update(['development_projection_pending' => true]);
        $calls = 0;
        $projector = Mockery::mock(DevelopmentRouteProjector::class);
        $projector->shouldReceive('converge')->twice()->andReturnUsing(function () use (&$calls): void {
            if (++$calls === 1) {
                throw new RuntimeConvergenceException('projection', 'app-dev.source_access_failed', 'Lost convergence.');
            }
        });
        app()->instance(DevelopmentRouteProjector::class, $projector);

        $this->artisan('orbit:auto-deploy')->expectsOutputToContain('app-dev.source_access_failed')->assertFailed();
        expect($this->instance->fresh()->development_projection_pending)->toBeTrue();

        $this->artisan('orbit:auto-deploy')->assertSuccessful();

        expect($calls)->toBe(2)
            ->and($this->instance->fresh()->development_projection_pending)->toBeFalse()
            ->and(Operation::query()->count())->toBe(0)
            ->and(InstanceDeployment::query()->count())->toBe(0);
    });

    it('takes no projection lock on an unchanged tick with a current projection', function (): void {
        $this->instance->update(['seed_commit' => str_repeat('b', 40)]);
        dev_defaults_route($this->instance, 'idle935.example.test');
        $this->instance->update(['development_projection_pending' => false]);
        $projector = Mockery::mock(DevelopmentRouteProjector::class);
        $projector->shouldNotReceive('converge');
        app()->instance(DevelopmentRouteProjector::class, $projector);
        app()->instance(DevelopmentProjectionOperationLock::class, new class implements DevelopmentProjectionOperationLock
        {
            public function run(Closure $operation): mixed
            {
                throw new LogicException('An unchanged tick with a current projection took the projection lock.');
            }
        });

        $this->artisan('orbit:auto-deploy')->assertSuccessful();

        expect($this->instance->fresh()->development_projection_pending)->toBeFalse();
    });
});
