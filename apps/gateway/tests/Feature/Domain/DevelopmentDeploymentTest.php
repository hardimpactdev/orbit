<?php

declare(strict_types=1);

use App\Actions\Instances\DeployDefaultInstanceAction;
use App\Actions\Instances\DeployInstanceAction;
use App\Actions\Instances\StartInstanceDeployAction;
use App\Domain\Instances\Deployment\DeploymentEvent;
use App\Domain\Instances\Deployment\DeploymentFailureBoundary;
use App\Domain\Instances\Deployment\DeploymentRequest;
use App\Domain\Instances\Deployment\DevelopmentDeployment;
use App\Domain\Instances\Deployment\InlineDeploymentSteps;
use App\Domain\Instances\DevelopmentRouteProjector;
use App\Domain\Projects\DevelopmentDeployStep;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentCaddyConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Route;
use Tests\Support\Dev935Deployment;

beforeEach(function (): void {
    $this->remote = new Dev935Deployment;
    app()->instance(DevelopmentDeployment::class, $this->remote);
    $this->instance = dev935_instance();
});

describe('development default deployments', function (): void {
    it('checks out the default branch in place and runs the Project steps there', function (): void {
        $this->instance->update(['deployment_branch' => 'ignored-production-override']);
        dev935_steps($this->instance, [new DevelopmentDeployStep('install', 'incremental install'), new DevelopmentDeployStep('build', 'build')]);
        $result = app(DeployInstanceAction::class)->execute($this->instance, new InlineDeploymentSteps);
        $instance = $this->instance->refresh();

        expect($result->succeeded)->toBeTrue()
            ->and($result->commit)->toBe(str_repeat('b', 40))
            ->and($result->release)->toBeNull()
            ->and($result->selectedRelease)->toBeNull()
            ->and(array_column($result->commands, 'step'))->toBe(['install', 'build'])
            ->and($this->remote->trace)->toBe(['target:main', 'checkout:'.str_repeat('b', 40), 'step:install', 'step:build'])
            ->and($instance->checkout_path)->toBe('/fast/apps/dev935/default')
            ->and($instance->seed_path)->toBe('/fast/apps/dev935/default')
            ->and($instance->seed_repository)->toBe('/fast/apps/dev935/default')
            ->and($instance->seed_commit)->toBe(str_repeat('b', 40))
            ->and($instance->development_release_layout)->toBeFalse();
    });

    it('serves a visitable default from its checkout', function (): void {
        $instance = $this->instance;
        $instance->update(['root' => 'public', 'selected_php_version' => '8.5', 'source_is_laravel' => true]);
        $route = Route::query()->create(['project_id' => $instance->project_id, 'node_id' => $instance->node_id, 'domain' => 'dev935.example.test', 'provenance' => 'explicit', 'publication' => 'public', 'status' => 'pending']);
        $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
        $route->update(['status' => 'active']);
        $projector = Mockery::mock(DevelopmentRouteProjector::class);
        $projector->shouldReceive('converge')->once()->andReturnUsing(function (Instance $projected) use ($instance): void {
            $site = new DevelopmentSiteRepository()->forNode($projected->node)->sole();
            $configuration = new DevelopmentCaddyConfigRenderer()->render(collect([$site]));
            expect($site->checkoutPath)->toBe($instance->checkout_path)
                ->and($configuration)->toContain('root * '.$instance->checkout_path.'/public')
                ->and($configuration)->not->toContain('resolve_root_symlink');
        });
        app()->instance(DevelopmentRouteProjector::class, $projector);

        expect(app(DeployDefaultInstanceAction::class)->execute($instance)?->succeeded)->toBeTrue()
            ->and($instance->fresh()->development_projection_pending)->toBeFalse();
        // A deployment in place does not move the Route, so it does not converge it again.
        expect(app(DeployDefaultInstanceAction::class)->execute($instance)?->succeeded)->toBeTrue();
    });

    it('leaves the checkout at the new commit and keeps the seed when a required step fails', function (): void {
        $this->instance->update(['seed_path' => $this->instance->checkout_path, 'seed_commit' => str_repeat('a', 40), 'seed_repository' => $this->instance->checkout_path]);
        dev935_steps($this->instance, [new DevelopmentDeployStep('install', 'false'), new DevelopmentDeployStep('build', 'never')]);
        $this->remote->failedStep = 'install';
        $result = app(DeployInstanceAction::class)->execute($this->instance, new InlineDeploymentSteps);

        expect($result->succeeded)->toBeFalse()
            ->and($result->failure?->boundary)->toBe(DeploymentFailureBoundary::BeforeActivation)
            ->and($result->failure?->errorCode)->toBe('deployment.step_failed')
            ->and($result->commit)->toBe(str_repeat('b', 40))
            ->and($result->selectedRelease)->toBeNull()
            ->and($result->commands[0]->result->exitCode)->toBe(42)
            ->and($this->remote->trace)->toBe(['target:main', 'checkout:'.str_repeat('b', 40), 'step:install'])
            ->and($this->instance->fresh()->seed_commit)->toBe(str_repeat('a', 40));
    });

    it('records a dirty checkout as a failed preparation', function (): void {
        $this->remote->checkoutFailure = new ResourceOperationException('deployment.checkout_dirty', 'The checkout has uncommitted changes to tracked files.', 409);
        $result = app(DeployDefaultInstanceAction::class)->execute($this->instance);

        expect($result->failure?->errorCode)->toBe('deployment.checkout_dirty')
            ->and($result->failure?->boundary)->toBe(DeploymentFailureBoundary::Preparation)
            ->and($result->commit)->toBe(str_repeat('b', 40))
            ->and($result->release)->toBeNull()
            ->and($this->instance->fresh()->seed_commit)->toBeNull();
    });

    it('checks out a pinned commit that the branch contains, also when the branch moved on', function (): void {
        $pinned = str_repeat('c', 40);
        $this->remote->targetCommit = str_repeat('d', 40);

        $result = app(DeployDefaultInstanceAction::class)->execute($this->instance, commit: $pinned);

        expect($result->succeeded)->toBeTrue()
            ->and($result->commit)->toBe($pinned)
            ->and($this->remote->trace)->toBe(['target:main@'.$pinned, 'checkout:'.$pinned])
            ->and($this->instance->fresh()->seed_commit)->toBe($pinned);
    });

    it('refuses a pinned commit that the branch does not contain and reports that commit', function (): void {
        $pinned = str_repeat('e', 40);
        $this->remote->notOnBranch = $pinned;

        $result = app(DeployDefaultInstanceAction::class)->execute($this->instance, commit: $pinned);

        expect($result->failure?->errorCode)->toBe('deployment.commit_not_on_branch')
            ->and($result->failure?->boundary)->toBe(DeploymentFailureBoundary::Preparation)
            ->and($result->commit)->toBe($pinned)
            ->and($this->remote->trace)->toBe(['target:main@'.$pinned]);
    });

    it('continues after a best-effort failure and records its explicit warning and exit code', function (): void {
        dev935_steps($this->instance, [new DevelopmentDeployStep('warm', 'false', required: false), new DevelopmentDeployStep('build', 'true')]);
        $this->remote->failedStep = 'warm';
        $events = [];
        $result = app(DeployDefaultInstanceAction::class)->execute($this->instance, new DeploymentRequest(static function (DeploymentEvent $event) use (&$events): void {
            $events[] = $event;
        }));

        expect($result->succeeded)->toBeTrue()
            ->and($result->commands[0]->result->exitCode)->toBe(42)
            ->and($this->remote->trace)->toContain('step:build')
            ->and($events[0]->step)->toBe('warm')
            ->and($events[0]->value)->toContain('Best-effort step failed (exit 42)');

        enable_operation_queue();
        app(StartInstanceDeployAction::class)->execute($this->instance, 'schedule', null, str_repeat('b', 40));
        run_operation_jobs();

        expect(InstanceDeployment::query()->sole()->status)->toBe('succeeded')
            ->and(InstanceDeployment::query()->sole()->commit)->toBe(str_repeat('b', 40))
            ->and(implode('', array_map(static fn (array $event): string => base64_decode((string) ($event['value_base64'] ?? '')), InstanceDeployment::query()->sole()->events ?? [])))
            ->toContain('Best-effort step failed (exit 42)');
    });

    it('keeps best-effort failures visible in scheduled history even after warm-up output is truncated', function (): void {
        dev935_steps($this->instance, [new DevelopmentDeployStep('warm', 'false', required: false)]);
        $this->remote->failedStep = 'warm';
        $this->remote->noisyFailure = true;
        enable_operation_queue();
        app(StartInstanceDeployAction::class)->execute($this->instance, 'schedule', null, str_repeat('b', 40));
        run_operation_jobs();
        $events = InstanceDeployment::query()->sole()->events ?? [];
        $output = array_values(array_filter($events, static fn (array $event): bool => $event['type'] === 'output'));
        $values = array_map(static fn (array $event): string => base64_decode($event['value_base64']), $output);

        expect(InstanceDeployment::query()->sole()->status)->toBe('succeeded')
            ->and(array_is_list($events))->toBeTrue()
            ->and(array_column($events, 'type'))->toContain('output_truncated')
            ->and(implode('', $values))->toContain('Best-effort step failed (exit 42): warm')
            ->and(array_sum(array_map(strlen(...), $values)))->toBeLessThanOrEqual(131_072);
    });

    it('refuses other development Instances and unsafe source states before any remote work', function (array $attributes): void {
        $this->instance->update($attributes);
        $result = app(DeployDefaultInstanceAction::class)->execute($this->instance);

        expect($result?->failure?->errorCode)->toBe('deployment_config.unavailable')
            ->and($this->remote->trace)->toBe([]);
    })->with([
        'task workspace' => [['name' => 'task-935']],
        'linked registered worktree' => [['source_layout' => 'linked_worktree']],
        'source resolved only' => [['status' => 'source_resolved']],
    ]);

});
