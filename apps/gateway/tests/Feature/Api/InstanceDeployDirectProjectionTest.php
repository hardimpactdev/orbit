<?php

declare(strict_types=1);

use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\Deployment\InstanceDeployProjection;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationContribution;
use App\Domain\Operations\OperationStatus;
use App\Models\InstanceDeployment;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Tests\TestCase;

function native_deploy_projection_fixture(TestCase $test, bool $production = false): array
{
    app()->instance(DirectOperation::class, new DirectOperation([new class implements DirectOperationContribution
    {
        public function definitions(): array
        {
            return ['instance.deploy' => ['workflow' => 'operation-instance-deploy-direct',
                'writer_config' => 'swamp.direct_writers', 'writer_state' => 'instance_deploy_writer',
                'projection' => InstanceDeployProjection::class]];
        }
    }]));
    if ($production) {
        [$gateway, , , $instance] = deployment_api_fixture();
        $instance->update(['production_user' => 'orbit-app-'.$instance->project_id,
            'production_home' => '/home/orbit-app-'.$instance->project_id]);
    } else {
        $instance = dev935_instance();
        $gateway = Node::query()->create(['name' => 'native-deploy-gateway', 'public_ssh_host' => '192.0.2.115',
            'wireguard_ip' => '10.44.0.115', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active']);
        NodeRole::query()->create(['node_id' => $gateway->id, 'role' => RoleName::Gateway, 'status' => 'active']);
    }
    $instance->node->accessibleNodes()->attach($gateway);
    config(['operation_executor.worker_node_ids' => [], 'dependency_detection.services_node_id' => null]);
    $test->withServerVariables(['REMOTE_ADDR' => $instance->node->wireguard_ip]);
    $operation = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance',
        'target_id' => $instance->id, 'node_id' => $instance->node_id, 'status' => OperationStatus::Queued,
        'input' => ['instance_id' => $instance->id, 'deployment' => app(InstanceDeploymentIntent::class)->snapshot($instance)],
        'state' => ['instance_deploy_writer' => 'direct', 'swamp_run_id' => '402775cd-6ab4-46d1-a92e-f84ae368fe62']]);
    $candidate = ['name' => InstanceDeploymentIntent::releaseName($operation), 'commit' => str_repeat('a', 40)];
    $payload = ['instance_id' => $instance->id, 'commit' => str_repeat('a', 40),
        'release' => $production ? $candidate : null,
        'previous' => $production ? ['name' => 'previous', 'commit' => str_repeat('b', 40)] : null,
        'selected_release' => $production ? $candidate : null,
        'selection_confirmed' => true, 'failed_step' => null, 'warnings' => 0];
    $body = ['run_id' => $operation->state['swamp_run_id'], 'status' => 'running',
        'started_at' => '2026-10-31T02:00:00.000Z', 'result' => [], 'error_code' => null];

    return [$instance, $operation, '/api/v1/direct-operations/'.$operation->id, $body, $payload];
}

it('starts one history row through the existing bound direct endpoint without a child', function (): void {
    [$instance, $operation, $path, $body] = native_deploy_projection_fixture($this);
    $this->postJson($path.'/result', $body)->assertOk()->assertJsonPath('data.status', 'running');
    $this->postJson($path.'/result', $body)->assertOk();
    expect(InstanceDeployment::query()->where('instance_id', $instance->id)->count())->toBe(1)
        ->and(Operation::query()->count())->toBe(1)->and($operation->fresh()->state['deployment_id'])->toBeInt();
});

it('projects native checkout success and keeps terminal replay independent of later configuration', function (): void {
    [$instance, $operation, $path, $body, $payload] = native_deploy_projection_fixture($this);
    $this->postJson($path.'/result', $body)->assertOk();
    $body = [...$body, 'status' => 'succeeded', 'finished_at' => '2026-10-31T02:00:01.000Z', 'result' => $payload];
    $response = $this->postJson($path.'/result', $body)->assertOk()->assertJsonPath('data.status', 'succeeded');
    $historyId = $response->json('data.result.deployment_id');
    expect($instance->fresh()->seed_commit)->toBe(str_repeat('a', 40))
        ->and(InstanceDeployment::query()->findOrFail($historyId)->status)->toBe('succeeded');
    $original = $instance->checkout_path;
    $instance->update(['checkout_path' => '/changed/after/completion', 'seed_commit' => str_repeat('b', 40)]);
    $this->getJson($path.'?run_id='.$body['run_id'])->assertOk()->assertJsonPath('data.input.deployment.home', $original);
    $this->postJson($path.'/result', $body)->assertOk();
    expect($instance->fresh()->seed_commit)->toBe(str_repeat('b', 40))
        ->and(InstanceDeployment::query()->count())->toBe(1)->and(Operation::query()->count())->toBe(1);
});

it('records uncertain activation without falsely projecting a selected production release', function (): void {
    [$instance, $operation, $path, $body, $payload] = native_deploy_projection_fixture($this, true);
    $original = $instance->checkout_path;
    $this->postJson($path.'/result', $body)->assertOk();
    $payload = [...$payload, 'selected_release' => null, 'selection_confirmed' => false, 'failed_step' => 'activation'];
    $response = $this->postJson($path.'/result', [...$body, 'status' => 'failed', 'finished_at' => '2026-10-31T02:00:01.000Z',
        'result' => $payload, 'error_code' => 'deployment.interrupted'])->assertOk()->assertJsonPath('data.result.selected_release', null);
    $history = InstanceDeployment::query()->findOrFail($response->json('data.result.deployment_id'));
    expect($history->status)->toBe('failed')->and($history->selected_release)->toBeNull()
        ->and($history->release)->toBe($payload['release']['name'])->and($instance->fresh()->checkout_path)->toBe($original);
});

it('refuses private or foreign facts before changing history or Instance state', function (): void {
    [$instance, $operation, $path, $body, $payload] = native_deploy_projection_fixture($this);
    $this->postJson($path.'/result', $body)->assertOk();
    $seed = $instance->fresh()->seed_commit;
    $payload['program'] = 'private command';
    $this->postJson($path.'/result', [...$body, 'status' => 'succeeded', 'finished_at' => '2026-10-31T02:00:01.000Z', 'result' => $payload])
        ->assertUnprocessable()->assertJsonPath('error.code', 'operation.result_invalid');
    expect($operation->fresh()->status)->toBe(OperationStatus::Running)
        ->and(InstanceDeployment::query()->sole()->status)->toBe('running')->and($instance->fresh()->seed_commit)->toBe($seed);
});

it('refuses a wrong native binding before history starts', function (): void {
    [$instance, $operation, $path, $body] = native_deploy_projection_fixture($this);
    $this->postJson($path.'/result', [...$body, 'run_id' => 'de337214-5652-4636-a510-aecf37b57824'])
        ->assertConflict()->assertJsonPath('error.code', 'operation.run_mismatch');
    expect(InstanceDeployment::query()->count())->toBe(0)->and($operation->fresh()->status)->toBe(OperationStatus::Queued);
});
