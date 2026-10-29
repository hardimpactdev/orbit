<?php

declare(strict_types=1);

use App\Domain\Instances\Deployment\DeploymentPhase;
use App\Domain\Instances\Deployment\DeploymentStep;
use App\Domain\Instances\Deployment\InstanceDeploymentBudget;
use App\Domain\Instances\Deployment\InstanceDeploymentBudgetCommand;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\Deployment\InstanceDeployStepStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

it('bounds a script against the original deployment clock across restart', function (): void {
    $this->freezeTime();
    [, $node, , $instance] = deployment_api_fixture();
    app(InstanceDeployStepStore::class)->replaceAll($instance, [new DeploymentStep('install', DeploymentPhase::BeforeActivation, 'install', 60)]);
    $operation = new Operation()->forceFill(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $node->id, 'started_at' => now()->subSeconds(950),
        'input' => ['instance_id' => $instance->id, 'deployment' => app(InstanceDeploymentIntent::class)->snapshot($instance)]]);

    $budget = app(InstanceDeploymentBudgetCommand::class)->run($operation, ['instance_id' => $instance->id, 'step' => 'before_activation.install']);

    expect($budget)->toBe(['budget_seconds' => 10, 'timeout_seconds' => 60, 'command_timeout_seconds' => 5]);
    $this->travel(11)->seconds();
    expect(fn () => app(InstanceDeploymentBudget::class)->requireRemaining($operation))
        ->toThrow(fn (ResourceOperationException $failure) => expect($failure->errorCode)->toBe('deployment.deadline_exceeded'));
});

it('caps long deploy plans at the Operation limit and rollback at its infrastructure budget', function (): void {
    $this->freezeTime();
    [, , , $instance] = deployment_api_fixture();
    app(InstanceDeployStepStore::class)->replaceAll($instance, [
        new DeploymentStep('install', DeploymentPhase::BeforeActivation, 'install', 540),
        new DeploymentStep('build', DeploymentPhase::BeforeActivation, 'build', 540),
    ]);
    $operation = new Operation()->forceFill(['started_at' => now()->subSeconds(1590),
        'input' => ['deployment' => app(InstanceDeploymentIntent::class)->snapshot($instance)]]);

    expect(app(InstanceDeploymentBudget::class)->remaining($operation))->toBe(10);
    $operation->input = ['deployment' => app(InstanceDeploymentIntent::class)->snapshot($instance, rollback: true)];
    expect(app(InstanceDeploymentBudget::class)->remaining($operation))->toBe(0);
});
