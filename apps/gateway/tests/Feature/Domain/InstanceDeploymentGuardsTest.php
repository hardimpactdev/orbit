<?php

declare(strict_types=1);

use App\Domain\Instances\Deployment\AutomaticDeployState;
use App\Domain\Instances\Deployment\InstanceDeploymentGuards;
use App\Domain\Instances\Deployment\InstanceDeploymentRecorder;
use App\Domain\Shared\ResourceOperationException;
use App\Models\InstanceDeployment;
use App\Models\Operation;
use App\Models\Route;

it('rechecks automatic admission changes before an effect without creating a history row', function (string $change, string $code): void {
    [, $node, , $instance] = deployment_api_fixture();
    $route = Route::query()->create(['project_id' => $instance->project_id, 'node_id' => $node->id, 'domain' => 'proof.example.test', 'provenance' => 'explicit', 'publication' => 'public', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);
    $instance->update(['status' => 'active', 'provisioning_step' => 'active', 'auto_deploy' => true]);
    $operation = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance',
        'target_id' => $instance->id, 'node_id' => $node->id, 'status' => 'running',
        'triggered_by' => 'swamp:auto-deploy', 'input' => ['commit' => str_repeat('a', 40), 'caller_node_id' => $node->id],
        'sequence' => 1, 'attempts' => 1]);
    match ($change) {
        'disabled' => $instance->update(['auto_deploy' => false]),
        'role' => $node->roles()->where('role', 'app-prod')->update(['status' => 'failed']),
        'hold' => $instance->update(['auto_deploy_state' => ['smoke_control' => ['hold' => ['key' => 'held']]]]),
        'blocked' => (function () use ($instance): void {
            $state = AutomaticDeployState::of($instance);
            $state->block(str_repeat('a', 40));
            $state->save($instance);
        })(),
    };
    $effects = 0;
    expect(fn () => app(InstanceDeploymentGuards::class)->around($operation, function () use (&$effects): void {
        $effects++;
    }))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe($code));
    expect($effects)->toBe(0)->and(InstanceDeployment::query()->count())->toBe(0);
})->with([
    'disabled after admission' => ['disabled', 'deployment.paused'],
    'inactive role after admission' => ['role', 'instance.auto_deploy_role_inactive'],
    'hold after admission' => ['hold', 'instance.smoke_held'],
    'blocked SHA after admission' => ['blocked', 'deployment.commit_blocked'],
]);

it('treats every manual history state as a pause for both automatic labels', function (string $label, string $status): void {
    [, $node, , $instance] = deployment_api_fixture();
    $route = Route::query()->create(['project_id' => $instance->project_id, 'node_id' => $node->id, 'domain' => 'proof.example.test', 'provenance' => 'explicit', 'publication' => 'public', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);
    $instance->update(['status' => 'active', 'provisioning_step' => 'active', 'auto_deploy' => true]);
    $operation = new Operation(['kind' => 'instance.deploy', 'target_type' => 'instance',
        'target_id' => $instance->id, 'node_id' => $node->id, 'triggered_by' => $label,
        'input' => ['commit' => str_repeat('b', 40), 'caller_node_id' => $node->id]]);
    $manual = app(InstanceDeploymentRecorder::class)->start($instance, 'operator');
    $manual->update(['status' => $status]);
    expect(fn () => app(InstanceDeploymentGuards::class)->around($operation, static fn () => null))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('deployment.paused'));
})->with(['orbit:auto-deploy', 'swamp:auto-deploy'])->with(['running', 'failed', 'succeeded']);
