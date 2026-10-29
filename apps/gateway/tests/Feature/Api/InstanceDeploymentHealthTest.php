<?php

declare(strict_types=1);

use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Models\Operation;

it('alerts on an unclaimed deploy or rollback after five minutes without changing work', function (string $kind): void {
    $this->freezeTime();
    [$caller, $node, , $instance] = deployment_api_fixture();
    $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip]);
    $operation = Operation::query()->create(['kind' => $kind, 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $node->id, 'status' => 'queued', 'sequence' => 1, 'lock_key' => 'instance:'.$instance->id,
        'input' => ['instance_id' => $instance->id, 'deployment' => app(InstanceDeploymentIntent::class)->snapshot($instance, $kind === 'instance.rollback')]]);
    $before = $operation->refresh()->getRawOriginal();
    $path = '/api/v1/operations/'.$operation->id;
    $this->travel(4)->minutes();
    $this->getJson($path)->assertOk()->assertJsonPath('data.assistance', null);
    $this->artisan('orbit:instance-deployment-operations-check')->assertSuccessful();
    $this->travel(1)->minutes();
    $this->getJson($path)->assertOk()->assertJsonPath('data.assistance.error_code', 'deployment.executor_unclaimed');
    $this->artisan('orbit:instance-deployment-operations-check')
        ->expectsOutputToContain('deployment.executor_unclaimed: Instance Operation '.$operation->id)->assertFailed();
    expect($operation->refresh()->getRawOriginal())->toBe($before)->and($operation->steps()->count())->toBe(0);

    $operation->update(['status' => 'running', 'started_at' => now()]);
    $this->getJson($path)->assertOk()->assertJsonPath('data.assistance', null);
    $this->artisan('orbit:instance-deployment-operations-check')->assertSuccessful();
})->with(['instance.deploy', 'instance.rollback']);

it('does not flag terminal or unrelated work as an unclaimed deployment', function (string $status): void {
    $this->freezeTime();
    [$caller, $node, , $instance] = deployment_api_fixture();
    $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip]);
    $operation = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $node->id, 'status' => $status, 'sequence' => 1, 'input' => []]);
    Operation::query()->create(['kind' => 'firewall.allow', 'target_type' => 'node', 'target_id' => $node->id,
        'node_id' => $node->id, 'status' => 'queued', 'input' => []]);
    $this->travel(6)->minutes();
    $this->getJson('/api/v1/operations/'.$operation->id)->assertOk()->assertJsonPath('data.assistance', null);
    $this->artisan('orbit:instance-deployment-operations-check')->assertSuccessful();
})->with(['succeeded', 'failed', 'cancelled']);
