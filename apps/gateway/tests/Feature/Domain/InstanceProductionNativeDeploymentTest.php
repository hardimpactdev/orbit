<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceDeployAction;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\Deployment\RecordedReleases;
use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Operation;
use App\Models\Process;
use App\Models\ProcessDefinition;
use App\Models\Route;
use Tests\Support\FakeRoutePublicationSocket;

function production_native_deploy_target(): Instance
{
    [, , , $instance] = deployment_api_fixture();
    $instance->node->update(['architecture' => 'x86_64']);
    $route = Route::query()->create(['project_id' => $instance->project_id, 'node_id' => $instance->node_id,
        'domain' => 'native-production.example.test', 'provenance' => 'explicit', 'publication' => 'public', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);
    $user = 'orbit-app-'.$instance->project_id;
    $instance->update(['production_user' => $user, 'production_home' => '/home/'.$user,
        'checkout_path' => '/home/'.$user.'/releases/previous', 'status' => 'active',
        'provisioning_step' => 'active', 'source_is_laravel' => false]);
    ProcessDefinition::query()->create(['project_id' => $instance->project_id, 'name' => 'queue', 'environments' => ['production'],
        'spec' => ['runtime' => 'systemd', 'command' => ['/usr/bin/true'], 'environment' => ['TOKEN' => 'private-member-token']]]);
    $instance->environmentValues()->create(['env_key' => 'API_TOKEN', 'env_value' => 'private-deploy-environment']);
    store_deploy_steps($instance, [
        ['name' => 'migrate', 'phase' => 'before_activation', 'command' => 'php artisan migrate --force # private-step-command', 'timeout_seconds' => 120],
        ['name' => 'warm', 'phase' => 'after_activation', 'command' => 'php artisan optimize', 'timeout_seconds' => 60],
    ]);

    return $instance->fresh();
}

/**
 * Project a production terminal from Swamp run data: every named step with its status and optional stdout.
 *
 * @param  array<string, string|array{0:string,1:string}>  $steps
 */
function project_production_terminal(Operation $operation, string $status, array $steps): Operation
{
    $runId = $operation->state['swamp_run_id'];
    $jobs = [['name' => 'deploy', 'steps' => array_map(static fn (string $name, string|array $step): array => ['name' => $name,
        'status' => is_array($step) ? $step[0] : $step, 'outputs' => is_array($step) ? ['stdout' => $step[1]] : []],
        array_keys($steps), $steps)]];
    $history = ['id' => $runId, 'workflowName' => 'operation-instance-deploy-direct', 'status' => $status,
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => $operation->kind],
        'startedAt' => '2026-11-02T22:00:00Z', 'completedAt' => '2026-11-02T22:01:00Z', 'jobs' => $jobs];
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => $history]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.read_token' => 'synthetic-native-reader']);

    return app(SwampCommandStep::class)->projectTerminal($operation, ['id' => $runId, 'status' => $status], app(DirectOperation::class)->definition('instance.deploy')['terminal_result']);
}

beforeEach(function (): void {
    $socket = $this->socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => $request['type'] === 'vault.put'
        ? [['type' => 'vault.put', 'id' => $request['id'], 'payload' => ['data' => ['vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['key'], 'vaultType' => 'local_encryption']]]]
        : [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => $request['payload']['workflowIdOrName'], 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.production-native.fixture']);
    $this->mock(AgentCommandRunner::class)->shouldNotReceive('run');
    $this->mock(ProcessRuntimeManager::class)->shouldNotReceive('converge');
});

it('admits one production deploy that holds the Instance, records its running row and starts the native run without secrets', function (): void {
    $instance = production_native_deploy_target();
    $operation = app(StartInstanceDeployAction::class)->execute($instance, 'operator', null);
    $copies = collect($this->socket->sent)->where('type', 'vault.put')->mapWithKeys(fn (array $put): array => [$put['payload']['key'] => $put['payload']['value']]);
    $submitted = collect($this->socket->sent)->firstWhere('type', 'workflow.run')['payload'];
    $intent = $submitted['inputs']['intent'];
    $home = $instance->production_home;
    $release = InstanceDeploymentIntent::releaseName($operation);
    $directory = "{$home}/releases/{$release}";

    expect($operation->lock_key)->toBe('instance:'.$instance->id)
        ->and($operation->input['run_budget_seconds'])->toBe(900 + 120 + 60)
        ->and(OperationRunBudgets::seconds($operation->fresh()))->toBe(1080)
        ->and(InstanceDeployment::query()->sole()->only(['kind', 'status']))->toBe(['kind' => 'deploy', 'status' => 'running'])
        ->and($operation->state['deployment_id'])->toBe(InstanceDeployment::query()->sole()->id)
        ->and($operation->input['direct_environment']['keys'])->toBe(['API_TOKEN', 'APP_ENV'])
        ->and($operation->input['php_input'])->toBeNull()
        ->and($operation->input['runtime_definitions']['processes'])->toHaveCount(1)
        ->and($submitted['workflowIdOrName'])->toBe('operation-instance-deploy-direct')
        ->and($intent['production']['release_path'])->toBe($directory)
        ->and($intent['production']['before'])->toBe([['index' => 0, 'name' => 'migrate', 'phase' => 'before_activation', 'timeout_seconds' => 120]])
        ->and($intent['production']['after'])->toBe([['index' => 1, 'name' => 'warm', 'phase' => 'after_activation', 'timeout_seconds' => 60]])
        ->and($intent['production']['prepare'][0]['argv'])->toContain('clone', $instance->project->repository_url, $directory)
        ->and($intent['production']['prepare'][1]['argv'])->toContain('refs/remotes/origin/main')
        ->and($intent['production']['links'])->toContain(['path' => "{$directory}/.env", 'target' => '../../.env'],
            ['path' => "{$directory}/storage", 'target' => "{$home}/storage"], ['path' => "{$directory}/public/storage", 'target' => '../storage/app/public'])
        ->and($intent['production']['grants'][0])->toBe(['/usr/bin/setfacl', '-m', 'u:caddy:--x', '--', $directory])
        ->and($intent['production']['cleanup'])->toBe([])
        ->and($intent['process_members'])->toHaveCount(1)
        ->and($submitted['inputs']['execution_secrets'])->toHaveKeys(['credential', 'step_0', 'step_1', 'environment'])
        ->and($copies['instance-deploy-operation-'.$operation->id.'-step-0'])->toBe("exec </dev/null >/dev/null 2>&1\nphp artisan migrate --force # private-step-command")
        ->and($copies['instance-environment-'.$operation->id])->toContain('private-deploy-environment')
        ->and(json_encode($submitted))->not->toContain('private-step-command')
        ->and(json_encode($submitted))->not->toContain('private-deploy-environment')
        ->and(json_encode($submitted))->not->toContain('private-member-token')
        ->and(json_encode($operation->input))->not->toContain('private-deploy-environment');
});

it('freezes the dedicated PHP-FPM service and refuses a second start while the deploy holds the Instance', function (): void {
    $instance = production_native_deploy_target();
    $identity = ProductionPhpRuntimeIdentity::forProvisioning($instance, '8.5');
    $instance->update(['selected_php_version' => '8.5', ...$identity->attributes()]);
    $operation = app(StartInstanceDeployAction::class)->execute($instance, 'operator', null);

    expect($operation->input['php_input'])->toBe(['service' => ProductionPhpRuntimeIdentity::from($instance->fresh())->service])
        ->and(fn () => app(StartInstanceDeployAction::class)->execute($instance, 'operator', null))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.target_busy'));
    expect(Operation::query()->count())->toBe(1)->and(InstanceDeployment::query()->count())->toBe(1);
});

it('rolls definition copies back when protected environment admission refuses', function (): void {
    $instance = production_native_deploy_target();
    $instance->update(['provisioning_step' => 'not-ready']);

    expect(fn () => app(StartInstanceDeployAction::class)->execute($instance, 'operator', null))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('env.owner_unavailable'));
    expect(Process::query()->count())->toBe(0)->and($instance->refresh()->runtime_definitions_captured_at)->toBeNull()
        ->and(Operation::query()->count())->toBe(0)->and(InstanceDeployment::query()->count())->toBe(0);
});

it('refuses a production account that is not the canonical admitted Project account', function (): void {
    $instance = production_native_deploy_target();
    $instance->update(['production_user' => 'foreign-account', 'production_home' => '/home/foreign-account']);
    expect(fn () => app(StartInstanceDeployAction::class)->execute($instance, 'operator', null))->toThrow(ResourceOperationException::class);
    expect(Operation::query()->count())->toBe(0)->and(Process::query()->count())->toBe(0)
        ->and($instance->refresh()->runtime_definitions_captured_at)->toBeNull();
});

it('refuses to start a run whose deploy step changed after admission', function (): void {
    $instance = production_native_deploy_target();
    $operation = app(StartInstanceDeployAction::class)->execute($instance, 'operator', null);
    store_deploy_steps($instance, [['name' => 'migrate', 'phase' => 'before_activation', 'command' => 'changed', 'timeout_seconds' => 120],
        ['name' => 'warm', 'phase' => 'after_activation', 'command' => 'php artisan optimize', 'timeout_seconds' => 60]]);

    expect(fn () => app(DirectOperation::class)->workflowInputs($operation->fresh()))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.execution_forbidden'));
});

it('records a succeeded deploy with its release, commit, selection, owned keys and installed Processes', function (): void {
    $instance = production_native_deploy_target();
    $operation = app(StartInstanceDeployAction::class)->execute($instance, 'operator', null)->fresh();
    $release = InstanceDeploymentIntent::releaseName($operation);
    $result = project_production_terminal($operation, 'succeeded', [
        'Read the release commit' => ['succeeded', str_repeat('c', 40)."\n"], 'Write .env-0' => 'succeeded',
        'Activate release' => 'succeeded', 'Swap current' => 'succeeded', 'Install Process definitions-0' => 'succeeded',
        'Find releases in use-0' => 'failed', 'Clean up releases-0' => 'skipped', 'Check the deployment' => 'succeeded']);

    expect($result->status)->toBe(OperationStatus::Succeeded)->and($result->lock_key)->toBeNull()
        ->and($result->result)->toMatchArray(['release' => $release, 'selected_release' => $release, 'commit' => str_repeat('c', 40), 'failed_step' => null, 'warnings' => 1])
        ->and(InstanceDeployment::query()->sole()->only(['status', 'release', 'selected_release', 'commit']))
        ->toBe(['status' => 'succeeded', 'release' => $release, 'selected_release' => $release, 'commit' => str_repeat('c', 40)])
        ->and($instance->fresh()->checkout_path)->toBe($instance->production_home.'/releases/'.$release)
        ->and($instance->fresh()->environment_owned_keys)->toBe(['API_TOKEN', 'APP_ENV'])
        ->and(Process::query()->sole()->status)->toBe(LifecycleStatus::Active);
});

it('keeps the earlier selection when a before step fails and names that step', function (): void {
    $instance = production_native_deploy_target();
    $operation = app(StartInstanceDeployAction::class)->execute($instance, 'operator', null)->fresh();
    $result = project_production_terminal($operation, 'failed', [
        'Read the release commit' => ['succeeded', str_repeat('c', 40)], 'Write .env-0' => 'succeeded', 'Run before steps-0' => 'failed',
        'Activate release' => 'skipped', 'Swap current' => 'skipped', 'Check the deployment' => 'skipped']);

    expect($result->status)->toBe(OperationStatus::Failed)->and($result->error_code)->toBe('deployment.step_failed')
        ->and($result->result)->toMatchArray(['failed_step' => 'before_activation.migrate', 'selected_release' => 'initial'])
        ->and(InstanceDeployment::query()->sole()->only(['status', 'error_code', 'selected_release']))
        ->toBe(['status' => 'failed', 'error_code' => 'deployment.step_failed', 'selected_release' => 'initial'])
        ->and($instance->fresh()->checkout_path)->toBe($instance->production_home.'/releases/previous')
        ->and(Process::query()->sole()->status)->not->toBe(LifecycleStatus::Active);
});

it('keeps the new selection when a Process restart fails after activation', function (): void {
    $instance = production_native_deploy_target();
    $operation = app(StartInstanceDeployAction::class)->execute($instance, 'operator', null)->fresh();
    $release = InstanceDeploymentIntent::releaseName($operation);
    $result = project_production_terminal($operation, 'failed', [
        'Activate release' => 'succeeded', 'Swap current' => 'succeeded', 'Install Process definitions-0' => 'failed',
        'Run after steps-0' => 'skipped', 'Check the deployment' => 'skipped']);

    expect($result->error_code)->toBe('process.install_failed')
        ->and($result->result)->toMatchArray(['failed_step' => 'Install Process definitions', 'selected_release' => $release])
        ->and($instance->fresh()->checkout_path)->toBe($instance->production_home.'/releases/'.$release)
        ->and(Process::query()->sole()->only(['status', 'error_code']))->toBe(['status' => LifecycleStatus::Failed, 'error_code' => 'process.install_failed']);
});

it('removes only the releases a successful deploy no longer keeps', function (): void {
    $instance = production_native_deploy_target();
    foreach (['r1', 'r2', 'r3'] as $release) {
        InstanceDeployment::query()->create(['instance_id' => $instance->id, 'kind' => 'deploy', 'branch' => 'main', 'release' => $release,
            'selected_release' => $release, 'commit' => str_repeat('a', 40), 'status' => 'succeeded', 'triggered_by' => 'operator',
            'started_at' => now(), 'finished_at' => now()]);
    }

    expect(RecordedReleases::of($instance))->toHaveKeys(['r1', 'r2', 'r3'])
        ->and(RecordedReleases::afterDeploy($instance, 'r4'))->toBe(['previous' => 'r3', 'remove' => ['r1']]);
});
