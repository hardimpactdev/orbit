<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\Deployment\DevelopmentDeployment;
use App\Domain\Instances\Deployment\DevelopmentTarget;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStepStatus;
use App\Domain\Projects\DevelopmentDeployStep;
use App\Domain\Projects\ProjectDevelopmentDeployStepStore;
use App\Infrastructure\Processes\CommandResult;
use App\Models\InstanceDeployment;
use App\Models\Operation;
use Tests\Support\Orb220DeploymentApiFixture;

it('deploys an app-dev default through the deploy operation and keeps best-effort warnings in history and the step', function (): void {
    enable_operation_queue();
    $fixture = Orb220DeploymentApiFixture::create();
    $fixture->owner->roles()->update(['role' => 'app-dev']);
    $home = '/fast/apps/deployment-stream/default';
    $fixture->instance->update(['name' => 'default', 'source_layout' => 'checkout', 'checkout_path' => $home]);
    app(ProjectDevelopmentDeployStepStore::class)->create($fixture->instance->project, new DevelopmentDeployStep('warm', 'false', required: false), null, null);
    $commit = str_repeat('b', 40);
    $remote = Mockery::mock(DevelopmentDeployment::class);
    $remote->shouldReceive('target')->once()->andReturn(new DevelopmentTarget($commit));
    $remote->shouldReceive('checkout')->once();
    $remote->shouldReceive('executeStep')->once()->andThrow(new RuntimeConvergenceException('deployment-step-warm', 'deployment.step_failed', 'Failed warm-up.', result: new CommandResult(42, '', '', 1, false)));
    app()->instance(DevelopmentDeployment::class, $remote);

    $response = $this->withServerVariables(['REMOTE_ADDR' => $fixture->caller->wireguard_ip])
        ->call('POST', "/api/v1/instances/{$fixture->instance->id}/deploy", server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}');
    $response->assertAccepted();
    run_operation_jobs();
    $operation = Operation::query()->sole();
    $warm = $operation->steps()->where('name', 'before_activation.warm')->sole();

    expect($operation->status)->toBe(OperationStatus::Succeeded)
        ->and($operation->result['selected_release'])->toBeNull()
        ->and($operation->steps()->pluck('name')->all())->toBe(['source_preparation', 'before_activation.warm'])
        ->and($warm->status)->toBe(OperationStepStatus::Failed)
        ->and($warm->error_code)->toBe('deployment.step_failed')
        ->and($warm->output)->toContain('Best-effort step failed (exit 42): warm')
        ->and($operation->failed_step)->toBeNull()
        ->and(InstanceDeployment::query()->sole()->status)->toBe('succeeded')
        ->and(InstanceDeployment::query()->sole()->selected_release)->toBeNull()
        ->and(InstanceDeployment::query()->sole()->commit)->toBe($commit)
        ->and($fixture->deployment->invocations)->toBe(0);
    $this->getJson("/api/v1/instances/{$fixture->instance->id}/releases")->assertOk()->assertJsonPath('data.selected_release', null)->assertJsonPath('data.releases', []);
    $history = $this->getJson("/api/v1/instances/{$fixture->instance->id}/deployments");
    $history->assertOk()->assertJsonPath('data.0.status', 'succeeded')->assertJsonPath('data.0.commit', $commit);
});
