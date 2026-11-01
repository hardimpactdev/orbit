<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceDeployAction;
use App\Domain\Instances\Deployment\AutomaticDeployHistory;
use App\Domain\Instances\Deployment\DevelopmentDeployInput;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\DevelopmentDeployStep;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\InstanceDeployment;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\ProjectDevelopmentDeployStep;
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
    if (! $production) {
        $operation->update(['input' => [...$operation->input, 'development_runtime' => app(DevelopmentDeployInput::class)->capture($instance, $vp), 'github' => ['$secret' => 'github.installation', 'id' => $instance->node_id]]]);
    }
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

function project_development_terminal(Operation $operation, string $status, array $payload): array
{
    $runId = $operation->state['swamp_run_id'];
    $history = ['id' => $runId, 'workflowName' => 'operation-instance-deploy-direct', 'status' => $status,
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => $operation->kind],
        'startedAt' => '2026-10-31T02:00:00Z', 'completedAt' => '2026-10-31T02:00:01Z',
        'jobs' => [['name' => 'development-result', 'steps' => [['name' => 'result', 'outputs' => ['stdout' => json_encode($payload, JSON_THROW_ON_ERROR)]]]]]];
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => $history]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.read_token' => 'synthetic-native-reader']);
    $step = app(SwampCommandStep::class);
    $selector = ['job' => 'development-result', 'step' => 'result'];
    $result = $step->projectTerminal($operation, ['id' => $runId, 'status' => $status], $selector);
    $step->projectTerminal($result, ['id' => $runId, 'status' => $status], $selector);

    return [$result, $socket];
}

it('refuses development callback and private-material paths before creating history', function (): void {
    [, $operation, $path, $body] = native_deploy_projection_fixture($this);
    $this->getJson($path.'?run_id='.$body['run_id'])->assertForbidden();
    $this->postJson($path.'/result', $body)->assertForbidden();
    $this->getJson($path.'/private-material?run_id='.$body['run_id'])->assertUnprocessable();
    expect(InstanceDeployment::query()->count())->toBe(0)->and($operation->fresh()->status)->toBe(OperationStatus::Queued);
});

it('projects only the bound native development terminal once and advances seed only on success', function (string $native, OperationStatus $expected): void {
    [$instance, $operation, , , $payload] = native_deploy_projection_fixture($this);
    $operation->update(['lock_key' => 'instance:'.$instance->id]);
    $seed = $instance->seed_commit;
    [$result, $socket] = project_development_terminal($operation, $native, $payload);
    expect($result->status)->toBe($expected)->and($result->lock_key)->toBeNull()
        ->and($socket->sent)->toHaveCount(1)->and(InstanceDeployment::query()->count())->toBe(1)
        ->and($instance->fresh()->seed_commit)->toBe($native === 'succeeded' ? str_repeat('a', 40) : $seed);
})->with([
    ['succeeded', OperationStatus::Succeeded], ['failed', OperationStatus::Failed],
    ['interrupted', OperationStatus::Interrupted], ['cancelled', OperationStatus::Cancelled],
]);

it('records only bounded public native facts and preserves terminal idempotency after owner changes', function (): void {
    [$instance, $operation, , , $payload] = native_deploy_projection_fixture($this);
    [$result] = project_development_terminal($operation, 'succeeded', [...$payload, 'program' => 'private-command-sentinel']);
    expect(json_encode($result->result))->not->toContain('private-command-sentinel');
    $instance->update(['checkout_path' => '/changed/after/completion', 'seed_commit' => str_repeat('b', 40)]);
    [, $socket] = project_development_terminal($result, 'succeeded', $payload);
    expect($socket->sent)->toBeEmpty()->and($instance->fresh()->seed_commit)->toBe(str_repeat('b', 40))
        ->and(InstanceDeployment::query()->count())->toBe(1);
});

it('records verified Vite pins from the development terminal against its admitted checkout', function (): void {
    [$instance, $operation, , , $payload] = native_deploy_projection_fixture($this, vp: true);
    $runtime = ['store' => '/opt/orbit/vite-plus', 'vp_version' => '1.4.0', 'node_version' => '24.22.0',
        'package_manager' => 'npm', 'package_manager_version' => '11.12.0', 'package_manager_hash' => null, 'status' => 'active'];
    project_development_terminal($operation, 'succeeded', [...$payload, 'vp_environment' => $runtime]);
    expect($instance->fresh()->vp_environment)->toMatchArray([...$runtime, 'checkout_path' => $instance->checkout_path]);
});

it('leaves a production deploy held when the native root terminates before its callback', function (): void {
    [$instance, $operation] = native_deploy_projection_fixture($this, production: true);
    $operation->update(['status' => OperationStatus::Running, 'lock_key' => 'instance:'.$instance->id]);
    [$result, $socket] = project_development_terminal($operation, 'succeeded', []);
    expect(app(DirectOperation::class)->usesNativeTerminal($operation))->toBeFalse()
        ->and($socket->sent)->toBeEmpty()->and($result->status)->toBe(OperationStatus::Running)
        ->and($result->lock_key)->toBe('instance:'.$instance->id)->and(InstanceDeployment::query()->count())->toBe(0);
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
        ->and(InstanceDeployment::query()->count())->toBe(0)->and(Operation::query()->count())->toBe(1)
        ->and($operation->lock_key)->toBe('instance:'.$instance->id);
    $this->call('POST', '/api/v1/instances/'.$instance->id.'/deploy', server: [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
    ], content: '{}')->assertConflict()->assertJsonPath('error.code', 'operation.target_busy')
        ->assertJsonPath('error.details.operation_id', $operation->id);
    app(StartSwampRun::class)->start($operation);
    expect($socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-instance-deploy-direct')
        ->and($socket->sent[0]['payload']['inputs'])->toMatchArray(['operation_id' => $operation->id,
            'kind' => 'instance.deploy', 'node_id' => $instance->node_id])
        ->and($socket->sent[0]['payload']['inputs']['intent']['deployment']['variant'])->toBe('development')
        ->and($socket->sent[0]['payload']['inputs']['intent']['ssh_execution']['control_node_id'])->toBe(209)
        ->and($operation->input)->not->toHaveKeys(['git_read', 'vp_materialize', 'environment_material', 'definitions_material']);
});

it('uses the sole native declaration even when a retired writer flag is set', function (): void {
    config(['swamp.direct_writers' => ['instance.deploy' => 'polled']]);
    expect(app(DirectOperation::class)->writer('instance.deploy', ['development' => true]))->toBe('direct')
        ->and(app(OperationKinds::class)->definition('instance.deploy')['workflow'])->toBe('operation-instance-deploy-direct');
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

it('refuses development Git grants after captured ownership changes', function (string $change): void {
    [$instance, $operation] = native_deploy_projection_fixture($this);
    $instance->project->update(['repository_url' => 'https://github.com/example/owned.git']);
    $intent = app(InstanceDeploymentIntent::class)->snapshot($instance);
    $operation->update(['input' => [...$operation->input, 'deployment' => $intent,
        'development_runtime' => app(DevelopmentDeployInput::class)->capture($instance->fresh(), false)]]);
    $grant = app(DirectOperation::class)->githubTokenGrant($operation->fresh());
    expect($grant->repository->owner.'/'.$grant->repository->name)->toBe('example/owned')->and($grant->permissions)->toBe(['contents' => 'read']);
    match ($change) {
        'repository' => $instance->project->update(['repository_url' => 'https://github.com/foreign/repository.git']),
        'account' => $instance->node->update(['user' => 'foreign']),
        'placement' => $instance->update(['checkout_path' => '/foreign/checkout']),
        'role' => $instance->node->roles()->update(['status' => 'failed']),
    };
    expect(fn () => app(DirectOperation::class)->githubTokenGrant($operation->fresh()))->toThrow(ResourceOperationException::class);
})->with(['repository', 'account', 'placement', 'role']);

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

it('refuses a keyed automatic deploy while another Operation holds the Instance and keeps a failed receipt', function (): void {
    $instance = dev935_instance();
    $instance->update(['auto_deploy' => true]);
    $gateway = $this->markAsGateway(Node::query()->create(['name' => 'automatic-busy-gateway', 'status' => 'active',
        'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.118', 'wireguard_ip' => '10.44.0.118']));
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.dev-deploy.fixture']);
    $holder = Operation::query()->create(['kind' => 'instance.rename', 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $instance->node_id, 'input' => [], 'status' => 'running', 'sequence' => 1, 'attempts' => 1,
        'lock_key' => 'instance:'.$instance->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    $path = '/api/v1/instances/'.$instance->id.'/deploy/automatic';
    $commit = str_repeat('a', 40);

    $this->postJson($path, ['commit' => $commit], ['Idempotency-Key' => 'auto.busy'])->assertConflict()
        ->assertJsonPath('error.code', 'operation.target_busy')->assertJsonPath('error.details.operation_id', $holder->id);
    $this->getJson($path.'/receipt?request_key=auto.busy&commit='.$commit)->assertOk()
        ->assertJsonPath('data.found', true)->assertJsonPath('data.operation.status', 'failed')
        ->assertJsonPath('data.operation.error_code', 'operation.target_busy')->assertJsonPath('data.operation.attempts', 0)
        ->assertJsonPath('data.operation.result', null);
    expect($socket->sent)->toBe([])
        ->and(Operation::query()->where('idempotency_key', 'auto.busy')->sole()->input)
        ->not->toHaveKeys(['git_read', 'vp_materialize', 'environment_material', 'definitions_material']);
});

it('records production Vite references against the native prepared release', function (): void {
    [$instance, $operation, $path, $body, $payload] = native_deploy_projection_fixture($this, production: true);
    $runtime = ['store' => '/opt/orbit/vite-plus', 'vp_version' => '1.4.0', 'node_version' => '24.22.0',
        'package_manager' => 'npm', 'package_manager_version' => '11.12.0', 'package_manager_hash' => null, 'status' => 'checking'];

    $this->postJson($path.'/result', [...$body, 'result' => ['release' => $payload['release'], 'vp_environment' => $runtime]])->assertOk();
    expect($instance->fresh()->vp_environment['checkout_path'])->toBe($instance->production_home.'/releases/'.$payload['release']['name'])
        ->and($operation->fresh()->status)->toBe(OperationStatus::Running);
});

it('copies each exact admitted command privately and refuses changed Project commands', function (): void {
    [$instance, $operation] = native_deploy_projection_fixture($this);
    dev935_steps($instance, [new DevelopmentDeployStep('first', 'printf private-copy-sentinel', 30, true), new DevelopmentDeployStep('second', 'true', 60, false)]);
    $operation->update(['input' => [...$operation->input, 'deployment' => app(InstanceDeploymentIntent::class)->snapshot($instance)]]);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => [['type' => 'vault.put', 'id' => $request['id'], 'payload' => ['data' => [
        'vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['key'], 'vaultType' => 'local_encryption']]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'synthetic-gateway-run-token']);
    $input = app(DevelopmentDeployInput::class)->workflowInput($operation);
    expect($input['execution_secrets'])->toBe([
        'step_0' => ['vault' => 'orbit-control-inputs', 'key' => 'development-deploy-operation-'.$operation->id.'-step-0'],
        'step_1' => ['vault' => 'orbit-control-inputs', 'key' => 'development-deploy-operation-'.$operation->id.'-step-1'],
    ])->and(json_encode([$input, $operation->fresh()->toArray()]))->not->toContain('private-copy-sentinel');
    expect($socket->sent)->toHaveCount(2)->and($socket->sent[0]['payload']['value'])->toBe('printf private-copy-sentinel');
    ProjectDevelopmentDeployStep::query()->where('project_id', $instance->project_id)->where('name', 'second')->update(['required' => true]);
    expect(fn () => app(DevelopmentDeployInput::class)->workflowInput($operation->fresh()))->toThrow(ResourceOperationException::class);
    expect($socket->sent)->toHaveCount(2);
});
