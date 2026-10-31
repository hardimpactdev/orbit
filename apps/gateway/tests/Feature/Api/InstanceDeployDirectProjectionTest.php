<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceDeployAction;
use App\Domain\Instances\Deployment\AutomaticDeployHistory;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\DevelopmentDeployStep;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Models\InstanceDeployment;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Route;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\TestCase;

function native_deploy_projection_fixture(TestCase $test, bool $production = false, bool $vp = false): array
{
    if ($production) {
        [$gateway, , , $instance] = deployment_api_fixture();
        $instance->update(['production_user' => 'orbit-app-'.$instance->project_id,
            'production_home' => '/home/orbit-app-'.$instance->project_id]);
    } else {
        $instance = dev935_instance();
        if ($vp) {
            dev935_steps($instance, [new DevelopmentDeployStep('install', 'vp install', 60, true)]);
        }
        $gateway = Node::query()->create(['name' => 'native-deploy-gateway', 'public_ssh_host' => '192.0.2.115',
            'wireguard_ip' => '10.44.0.115', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active']);
        NodeRole::query()->create(['node_id' => $gateway->id, 'role' => RoleName::Gateway, 'status' => 'active']);
    }
    $instance->node->accessibleNodes()->attach($gateway);
    config(['operation_executor.worker_node_ids' => [], 'dependency_detection.services_node_id' => null]);
    $test->withServerVariables(['REMOTE_ADDR' => $instance->node->wireguard_ip]);
    $operation = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance',
        'target_id' => $instance->id, 'node_id' => $instance->node_id, 'status' => OperationStatus::Queued,
        'input' => ['platform' => 'linux', 'architecture' => 'x86_64', 'instance_id' => $instance->id, 'development' => ! $production, 'deployment' => app(InstanceDeploymentIntent::class)->snapshot($instance),
            'git_read' => ['$secret' => 'instance.deployment', 'id' => $instance->id],
            'vp_materialize' => ['$secret' => 'instance.deployment', 'id' => $instance->id],
            'environment_material' => ['$secret' => 'instance.deployment', 'id' => $instance->id],
            'definitions_material' => ['$secret' => 'instance.deployment', 'id' => $instance->id]],
        'state' => ['instance_deploy_writer' => 'direct', 'swamp_run_id' => '402775cd-6ab4-46d1-a92e-f84ae368fe62']]);
    $candidate = ['name' => InstanceDeploymentIntent::releaseName($operation), 'commit' => str_repeat('a', 40)];
    $payload = ['instance_id' => $instance->id, 'commit' => str_repeat('a', 40),
        'release' => $production ? $candidate : null,
        'previous' => $production ? ['name' => 'previous', 'commit' => str_repeat('b', 40)] : null,
        'selected_release' => $production ? $candidate : null,
        'selection_confirmed' => true, 'failed_step' => null, 'warnings' => 0, 'output_tail' => null];
    $body = ['run_id' => $operation->state['swamp_run_id'], 'status' => 'running',
        'started_at' => '2026-10-31T02:00:00.000Z', 'result' => [], 'error_code' => null];

    return [$instance, $operation, '/api/v1/direct-operations/'.$operation->id, $body, $payload];
}

it('returns immutable Node execution intent without an agent action digest', function (): void {
    [, $operation, $path, $body] = native_deploy_projection_fixture($this);
    $this->getJson($path.'?run_id='.$body['run_id'])->assertOk()
        ->assertJsonPath('data.input.platform', 'linux')->assertJsonPath('data.input.architecture', 'x86_64');
    expect($operation->fresh()->input)->not->toHaveKey('agent_sha256');
});

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

it('records only bounded public facts from the owning root result', function (): void {
    [$instance, $operation, $path, $body, $payload] = native_deploy_projection_fixture($this);
    $payload['program'] = 'private command';
    $this->postJson($path.'/result', [...$body, 'status' => 'succeeded', 'finished_at' => '2026-10-31T02:00:01.000Z', 'result' => $payload])
        ->assertOk()->assertJsonMissingPath('data.result.program');
    expect(json_encode($operation->fresh()->result))->not->toContain('private command')
        ->and($instance->fresh()->seed_commit)->toBe(str_repeat('a', 40));
});

it('refuses a wrong native binding before history starts', function (): void {
    [$instance, $operation, $path, $body] = native_deploy_projection_fixture($this);
    $this->postJson($path.'/result', [...$body, 'run_id' => 'de337214-5652-4636-a510-aecf37b57824'])
        ->assertConflict()->assertJsonPath('error.code', 'operation.run_mismatch');
    expect(InstanceDeployment::query()->count())->toBe(0)->and($operation->fresh()->status)->toBe(OperationStatus::Queued);
});

it('records public Vite runtime references from its actual development parent without children', function (): void {
    [$instance, $operation, $path, $body] = native_deploy_projection_fixture($this, vp: true);
    $runtime = ['store' => '/opt/orbit/vite-plus', 'vp_version' => '1.4.0', 'node_version' => '24.22.0',
        'package_manager' => 'npm', 'package_manager_version' => '11.12.0', 'package_manager_hash' => null, 'status' => 'checking'];

    $this->postJson($path.'/result', [...$body, 'result' => ['vp_environment' => $runtime]])->assertOk();
    $runtime['status'] = 'active';
    $this->postJson($path.'/result', [...$body, 'result' => ['vp_environment' => $runtime]])->assertOk();

    expect($instance->fresh()->vp_environment)->toMatchArray([...$runtime, 'checkout_path' => $instance->checkout_path])
        ->and(InstanceDeployment::query()->count())->toBe(1)->and(Operation::query()->count())->toBe(1)
        ->and($operation->fresh()->status)->toBe(OperationStatus::Running);
});

it('admits the real dev writer with only a native start and no Gateway step execution', function (): void {
    $instance = dev935_instance();
    $gateway = $this->markAsGateway(Node::query()->create(['name' => 'dev-deploy-admission-gateway', 'status' => 'active',
        'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.116', 'wireguard_ip' => '10.44.0.116']));
    $instance->node->accessibleNodes()->attach($gateway);
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.dev-deploy.fixture']);
    $this->withServerVariables(['REMOTE_ADDR' => $instance->node->wireguard_ip]);

    $response = $this->call('POST', '/api/v1/instances/'.$instance->id.'/deploy', server: [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
    ], content: '{}')->assertAccepted();
    $operation = Operation::query()->findOrFail($response->json('data.id'));

    expect($operation->input)->not->toHaveKey('agent_sha256')
        ->and($operation->input['deployment']['variant'])->toBe('development')
        ->and($operation->input['deployment']['node_user'])->toBe($instance->node->user)
        ->and(InstanceDeployment::query()->count())->toBe(0)->and(Operation::query()->count())->toBe(1);
    app(StartSwampRun::class)->start($operation);
    expect($socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-instance-deploy-direct')
        ->and($socket->sent[0]['payload']['inputs'])->toBe(['operation_id' => $operation->id,
            'kind' => 'instance.deploy', 'node_id' => $instance->node_id]);
});

it('uses the sole native declaration even when a retired writer flag is set', function (): void {
    config(['swamp.direct_writers' => ['instance.deploy' => 'polled']]);
    expect(app(DirectOperation::class)->writer('instance.deploy', ['development' => true]))->toBe('direct')
        ->and(app(OperationKinds::class)->definition('instance.deploy')['workflow'])->toBe('operation-instance-deploy-direct');
});

it('transports public repository Git absence privately without Agent or child execution', function (): void {
    [$instance, $operation, $path, $body] = native_deploy_projection_fixture($this);

    $this->getJson($path.'/private-material?run_id='.$body['run_id'])->assertOk()
        ->assertJsonPath('data.git_read', null)->assertJsonPath('data.vp_materialize', []);

    expect($operation->fresh()->status)->toBe(OperationStatus::Queued)
        ->and(Operation::query()->count())->toBe(1);
});

it('keeps private delivery bound to admitted ownership without runtime execution', function (): void {
    [$instance, $operation, $path, $body] = native_deploy_projection_fixture($this);
    $other = Node::query()->create(['name' => 'foreign-deploy-node', 'public_ssh_host' => '192.0.2.199', 'status' => 'active', 'user' => 'orbit', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.199']);
    $instance->update(['node_id' => $other->id]);
    $this->getJson($path.'/private-material?run_id='.$body['run_id'])->assertUnprocessable();
    expect($operation->fresh()->status)->toBe(OperationStatus::Queued);
});

it('refuses manual deployment until the existing default Route repair finishes', function (): void {
    $instance = dev935_instance();
    $instance->update(['development_projection_pending' => true]);
    $route = Route::query()->create(['kind' => 'app', 'project_id' => $instance->project_id,
        'node_id' => $instance->node_id, 'domain' => 'pending-dev.example.test', 'publication' => 'private', 'provenance' => 'explicit', 'status' => 'pending']);
    $instance->routes()->attach($route->id, ['position' => 0]);
    $route->update(['status' => 'active']);

    expect(fn () => app(StartInstanceDeployAction::class)->execute($instance->fresh(), 'operator', null))
        ->toThrow(fn (ResourceOperationException $e) => expect($e->errorCode)->toBe('deployment.projection_pending'));
    expect(Operation::query()->count())->toBe(0);
});

function native_development_policy_route(TestCase $test): array
{
    [$instance] = native_deploy_projection_fixture($test);
    $instance->update(['development_projection_pending' => true]);
    $route = Route::query()->create(['kind' => 'app', 'project_id' => $instance->project_id,
        'node_id' => $instance->node_id, 'domain' => 'policy-repair.example.test', 'publication' => 'private', 'provenance' => 'explicit', 'status' => 'pending']);
    $instance->routes()->attach($route->id, ['position' => 0]);
    $route->update(['status' => 'active']);
    $receipt = Operation::query()->create(['kind' => 'route.update', 'target_type' => 'route', 'target_id' => $route->id,
        'node_id' => $instance->node_id, 'status' => OperationStatus::Succeeded,
        'input' => ['mode' => 'publication', 'domain' => $route->domain, 'publication' => $route->publication->value]]);
    $body = ['resumed_after' => 0, 'last_deployment_id' => 0,
        'history_version' => new AutomaticDeployHistory($instance)->snapshot()->version,
        'result' => 'busy', 'commit' => str_repeat('a', 40), 'head' => str_repeat('a', 40), 'since' => now()->toIso8601String(),
        'error_code' => null, 'message' => null, 'pause' => null, 'route_operation_id' => $receipt->id];

    return [$instance, $receipt, $body];
}

it('projects only the matching completed authoritative Route publication without a repair child', function (): void {
    [$instance, , $body] = native_development_policy_route($this);
    $this->putJson('/api/v1/instances/'.$instance->id.'/auto-deploy/policy', $body)->assertOk();
    expect($instance->fresh()->development_projection_pending)->toBeFalse()
        ->and(Operation::query()->count())->toBe(2)->and(InstanceDeployment::query()->count())->toBe(0);
});

it('refuses an unrelated or unfinished Route receipt before changing development publication state', function (string $fault): void {
    [$instance, $receipt, $body] = native_development_policy_route($this);
    match ($fault) {
        'kind' => $receipt->update(['kind' => 'route.create']),
        'status' => $receipt->update(['status' => OperationStatus::Running]),
        'target' => $receipt->update(['target_id' => $receipt->target_id + 1]),
        'mode' => $receipt->update(['input' => [...$receipt->input, 'mode' => 'state_only']]),
        'domain' => $receipt->update(['input' => [...$receipt->input, 'domain' => 'foreign.example.test']]),
        'publication' => $receipt->update(['input' => [...$receipt->input, 'publication' => 'public']]),
    };
    $this->putJson('/api/v1/instances/'.$instance->id.'/auto-deploy/policy', $body)
        ->assertStatus(409)->assertJsonPath('error.code', 'instance.projection_unproven');
    expect($instance->fresh()->development_projection_pending)->toBeTrue();
})->with(['kind', 'status', 'target', 'mode', 'domain', 'publication']);

it('exposes Swamp as the development policy owner and removes the PHP tick and repair kind', function (): void {
    [$instance] = native_deploy_projection_fixture($this);
    $this->getJson('/api/v1/instances/'.$instance->id)->assertOk()->assertJsonPath('data.auto_deploy.policy_owner', 'swamp');
    expect(array_keys(app(Kernel::class)->all()))->not->toContain('orbit:auto-deploy')
        ->and(OperationKinds::definitions())->not->toHaveKey('instance.repair_projection');
});

it('refuses development policy publication when the app-dev role is no longer active', function (): void {
    [$instance, , $body] = native_development_policy_route($this);
    $instance->node->roles()->where('role', RoleName::AppDev)->update(['status' => 'failed']);
    $this->putJson('/api/v1/instances/'.$instance->id.'/auto-deploy/policy', $body)
        ->assertStatus(409)->assertJsonPath('error.code', 'instance.auto_deploy_role_inactive');
    expect($instance->fresh()->development_projection_pending)->toBeTrue();
});

it('exposes only pending authoritative development publication instead of the display Route fallback', function (): void {
    [$instance, $receipt] = native_development_policy_route($this);
    $url = '/api/v1/instances/'.$instance->id;
    $this->getJson($url)->assertOk()->assertJsonPath('data.development_projection_route_id', $receipt->target_id);
    Route::query()->findOrFail($receipt->target_id)->update(['status' => 'pending']);
    $this->getJson($url)->assertOk()->assertJsonPath('data.route.id', $receipt->target_id)
        ->assertJsonPath('data.development_projection_route_id', null);
    expect($instance->fresh()->development_projection_pending)->toBeTrue();
});

it('settles a native pre-effect interruption and releases the Instance hold without advancing seed', function (): void {
    [$instance, $operation, $path, $body, $payload] = native_deploy_projection_fixture($this);
    $operation->update(['lock_key' => 'instance:'.$instance->id]);
    $seed = $instance->seed_commit;
    $this->postJson($path.'/result', $body)->assertOk();
    $payload['failed_step'] = 'source_preparation';
    $this->postJson($path.'/result', [...$body, 'status' => 'failed', 'error_code' => 'deployment.interrupted',
        'finished_at' => '2026-10-31T02:00:01.000Z', 'result' => $payload])->assertOk()->assertJsonPath('data.status', 'failed');
    expect($operation->fresh()->lock_key)->toBeNull()->and($instance->fresh()->seed_commit)->toBe($seed)
        ->and(InstanceDeployment::query()->sole()->error_code)->toBe('deployment.interrupted');
});

it('projects only the bounded redacted failed-step tail into the Operation and history', function (): void {
    [, , $path, $body, $payload] = native_deploy_projection_fixture($this);
    $this->postJson($path.'/result', $body)->assertOk();
    $payload['failed_step'] = 'before_activation';
    $payload['output_tail'] = 'Build failed: TOKEN=[REDACTED]';
    $this->postJson($path.'/result', [...$body, 'status' => 'failed', 'error_code' => 'deployment.step_failed',
        'finished_at' => '2026-10-31T02:00:01.000Z', 'result' => $payload])->assertOk()
        ->assertJsonPath('data.result.output_tail', $payload['output_tail']);
    $events = InstanceDeployment::query()->sole()->events;
    expect(base64_decode($events[0]['value_base64'], true))->toBe($payload['output_tail']);
});

it('advertises the canonical automatic eligibility for an ambiguous app-dev plus app-prod Node', function (): void {
    [$instance] = native_deploy_projection_fixture($this);
    NodeRole::query()->create(['node_id' => $instance->node_id, 'role' => RoleName::AppProd, 'status' => 'active']);
    $this->getJson('/api/v1/instances/'.$instance->id)->assertOk()->assertJsonPath('data.auto_deploy.eligible', false);
});

it('settles a cancelled interrupted native step as failed unknown and releases the shared target hold', function (): void {
    [$instance, $operation, $path, $body, $payload] = native_deploy_projection_fixture($this, vp: true);
    $seed = $instance->fresh()->seed_commit;
    $operation->update(['lock_key' => 'instance:'.$instance->id, 'cancel_requested_at' => now()]);
    $this->postJson($path.'/result', $body)->assertOk();
    $payload['failed_step'] = 'before_activation.install';
    $payload['step_outcome'] = 'unknown';
    $terminal = [...$body, 'status' => 'failed', 'error_code' => 'deployment.interrupted',
        'finished_at' => '2026-10-31T02:01:00.000Z', 'result' => $payload];
    $this->postJson($path.'/result', $terminal)->assertOk()->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.result.failed_step', 'before_activation.install')
        ->assertJsonPath('data.result.step_outcome', 'unknown');
    $this->postJson($path.'/result', $terminal)->assertOk();
    expect($operation->refresh()->lock_key)->toBeNull()->and($operation->error_code)->toBe('deployment.interrupted')
        ->and($instance->fresh()->seed_commit)->toBe($seed)->and(InstanceDeployment::query()->sole()->status)->toBe('failed')
        ->and(Operation::query()->count())->toBe(1);
});

it('records an interrupted native result without Gateway step or receipt validation', function (): void {
    [, $operation, $path, $body, $payload] = native_deploy_projection_fixture($this, vp: true);
    $payload['failed_step'] = 'before_activation.install';
    $payload['step_outcome'] = 'unknown';
    $this->postJson($path.'/result', [...$body, 'status' => 'failed', 'error_code' => 'deployment.interrupted',
        'finished_at' => '2026-10-31T02:01:00.000Z', 'result' => $payload])->assertOk();
    expect($operation->fresh()->status)->toBe(OperationStatus::Failed)
        ->and($operation->fresh()->result['step_outcome'])->toBe('unknown');
});

it('refuses private Git hydration after admitted source ownership changes', function (string $change): void {
    [$instance, $operation, $path, $body] = native_deploy_projection_fixture($this);
    if ($change === 'repository') {
        $instance->project->update(['repository_url' => 'https://example.test/foreign.git']);
    } else {
        $instance->node->update(['user' => 'foreign']);
    }
    $this->getJson($path.'?run_id='.$body['run_id'])->assertOk();
    $this->getJson($path.'/private-material?run_id='.$body['run_id'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
    expect($operation->fresh()->status)->toBe(OperationStatus::Queued);
})->with(['repository', 'account']);

it('admits an automatic dev commit once and dispatches the direct root', function (): void {
    $instance = dev935_instance();
    $instance->update(['auto_deploy' => true]);
    $gateway = $this->markAsGateway(Node::query()->create(['name' => 'automatic-dev-gateway', 'status' => 'active',
        'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.117', 'wireguard_ip' => '10.44.0.117']));
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.dev-deploy.fixture']);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    $path = '/api/v1/instances/'.$instance->id.'/deploy/automatic';
    $body = ['commit' => str_repeat('a', 40)];
    $id = $this->postJson($path, $body, ['Idempotency-Key' => 'native.auto.dev.proof'])->assertAccepted()->json('data.id');
    $this->postJson($path, $body, ['Idempotency-Key' => 'native.auto.dev.proof'])->assertAccepted()->assertJsonPath('data.id', $id);
    $operation = Operation::query()->findOrFail($id);
    app(StartSwampRun::class)->start($operation);
    expect($operation->input['deployment']['node_user'])->toBe($instance->node->user)
        ->and($operation->input['commit'])->toBe($body['commit'])
        ->and(Operation::query()->count())->toBe(1)
        ->and($socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-instance-deploy-direct');
});

it('records production Vite references against the native prepared release', function (): void {
    [$instance, $operation, $path, $body, $payload] = native_deploy_projection_fixture($this, production: true);
    $runtime = ['store' => '/opt/orbit/vite-plus', 'vp_version' => '1.4.0', 'node_version' => '24.22.0',
        'package_manager' => 'npm', 'package_manager_version' => '11.12.0', 'package_manager_hash' => null, 'status' => 'checking'];

    $this->postJson($path.'/result', [...$body, 'result' => ['release' => $payload['release'], 'vp_environment' => $runtime]])->assertOk();
    expect($instance->fresh()->vp_environment['checkout_path'])->toBe($instance->production_home.'/releases/'.$payload['release']['name'])
        ->and($operation->fresh()->status)->toBe(OperationStatus::Running);
});
