<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceEnvironmentSyncAction;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\NativeInstanceEnvironmentProjection;
use App\Domain\Instances\Environment\NativeInstanceEnvironmentSync;
use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Process;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->socket = $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => $request['type'] === 'vault.put'
        ? [['type' => 'vault.put', 'id' => $request['id'], 'payload' => ['data' => ['vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['key'], 'vaultType' => 'local_encryption']]]]
        : [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => $request['payload']['workflowIdOrName'], 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'synthetic-shared-run-token']);
});

function nativeEnvironmentInstance(): Instance
{
    $instance = dev935_instance('native-environment');
    $instance->node->update(['architecture' => null]);
    $instance->forceFill(['provisioning_step' => 'active', 'source_is_laravel' => false, 'environment_owned_keys' => ['OLD']])->saveOrFail();
    $instance->environmentValues()->create(['env_key' => 'API_TOKEN', 'env_value' => 'synthetic-private-value']);

    return $instance->refresh();
}

/** @return array{type:string,payload:array<string,mixed>} */
function nativeEnvironmentFrame(FakeRoutePublicationSocket $socket, string $type): array
{
    return collect($socket->sent)->firstWhere('type', $type);
}

it('admits one task whose plan holds key names and the rendered hash, then pushes the file to the vault and starts the run', function (): void {
    $instance = nativeEnvironmentInstance();
    $operation = app(StartInstanceEnvironmentSyncAction::class)->execute($instance);
    $plan = $operation->input['direct_environment'];
    $content = "API_TOKEN=\"synthetic-private-value\"\n";
    expect($operation->kind)->toBe('instance.sync_environment')
        ->and($plan)->toMatchArray(['instance_id' => $instance->id, 'node_id' => $instance->node_id, 'environment' => 'development',
            'user' => $instance->node->user, 'keys' => ['API_TOKEN'], 'owned_keys' => ['OLD'], 'sha256' => hash('sha256', $content), 'testing' => null, 'runtime' => null])
        ->and(json_encode($operation->input))->not->toContain('synthetic-private-value');
    $put = nativeEnvironmentFrame($this->socket, 'vault.put');
    expect($put['payload'])->toMatchArray(['vaultName' => 'orbit-control-inputs', 'key' => 'instance-environment-'.$operation->id])
        ->and($put['payload']['value'])->toBe($content);
    $run = collect($this->socket->sent)->last();
    expect($run['payload']['workflowIdOrName'])->toBe('operation-instance-environment')
        ->and(json_encode($run))->not->toContain('synthetic-private-value')
        ->and($run['payload']['inputs']['intent']['environment'])->not->toHaveKeys(['node_id', 'runtime'])
        ->and($run['payload']['inputs']['intent']['ssh_execution']['target']['host'])->toBe($instance->node->wireguard_ip)
        ->and($run['payload']['inputs']['execution_secrets'])->toBe(['environment' => ['vault' => 'orbit-control-inputs', 'key' => 'instance-environment-'.$operation->id]])
        ->and($operation->fresh()->state['swamp_run_id'])->toBe('a075eb23-7ac4-4487-80d1-57a038a92676');
});

it('refuses to start when the values or the placement changed after admission', function (string $change): void {
    $instance = nativeEnvironmentInstance();
    $context = app(InstanceEnvironmentContextResolver::class)->resolve($instance, true);
    $plan = app(NativeInstanceEnvironmentSync::class)->plan($context);
    $operation = Operation::query()->create(['kind' => 'instance.sync_environment', 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $instance->node_id, 'status' => 'queued', 'input' => ['instance_id' => $instance->id, 'direct_environment' => $plan]]);
    if ($change === 'value') {
        $instance->environmentValues()->firstOrFail()->update(['env_value' => 'changed']);
    } else {
        $operation->input = ['instance_id' => $instance->id, 'direct_environment' => [...$plan, 'user' => 'root']];
    }
    expect(fn () => app(NativeInstanceEnvironmentSync::class)->inputs($operation))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe($change === 'value' ? 'env.configuration_changed' : 'operation.execution_forbidden'))
        ->and($this->socket->sent)->toBe([]);
})->with(['value', 'placement']);

function nativeProductionEnvironmentInstance(bool $php = true): Instance
{
    $instance = dev935_instance('native-production-environment', role: 'app-prod');
    $home = '/home/orbit-app-'.$instance->project_id;
    $instance->node->update(['architecture' => 'x86_64']);
    $instance->project->update(['root' => 'apps/site/public']);
    $instance->update(['environment' => 'production', 'production_user' => 'orbit-app-'.$instance->project_id,
        'production_home' => $home, 'checkout_path' => $home.'/releases/r1',
        'source_is_laravel' => true, 'provisioning_step' => 'active', 'selected_php_version' => $php ? '8.5' : null]);
    if ($php) {
        $instance->update(ProductionPhpRuntimeIdentity::forProvisioning($instance, '8.5')->attributes());
    }
    $instance->environmentValues()->create(['env_key' => 'APP_KEY', 'env_value' => 'synthetic-private-value']);

    return $instance->refresh();
}

it('plans a production refresh with the config cache, the FPM service and only opted-in running Processes', function (): void {
    $instance = nativeProductionEnvironmentInstance();
    $attributes = ['owner_type' => $instance->getMorphClass(), 'owner_id' => $instance->id,
        'runtime' => 'systemd', 'runtime_config' => ['command' => ['/usr/bin/sleep', '30']],
        'restart_policy' => 'always', 'restart_on_deploy' => true, 'desired_state' => 'running', 'status' => 'active'];
    $process = Process::query()->create(['name' => 'worker', ...$attributes]);
    foreach ([['restart_on_deploy' => false], ['status' => 'failed'], ['desired_state' => 'stopped'], ['owner_id' => $instance->id + 1]] as $index => $override) {
        Process::query()->create(['name' => 'excluded-'.$index, ...$attributes, ...$override]);
    }
    $operation = app(StartInstanceEnvironmentSyncAction::class)->execute($instance);
    $plan = $operation->input['direct_environment'];
    $identity = ProductionPhpRuntimeIdentity::from($instance);
    expect($plan['runtime']['config'])->toBe(['user' => $instance->production_user, 'home' => $instance->production_home,
        'version' => '8.5', 'application' => $instance->production_home.'/current/apps/site'])
        ->and($plan['runtime']['fpm_service'])->toBe($identity->service)
        ->and($plan['runtime']['processes'])->toBe([['id' => $process->id, 'runtime' => 'systemd', 'name' => 'orbit-process-'.$process->id.'-worker.service']])
        ->and(json_encode($operation->input))->not->toContain('synthetic-private-value');
});

it('plans a production file sync without PHP with no config cache or FPM reload', function (): void {
    $instance = nativeProductionEnvironmentInstance(false);
    $instance->node->update(['architecture' => null]);
    $operation = app(StartInstanceEnvironmentSyncAction::class)->execute($instance);
    expect($operation->input['direct_environment']['runtime'])->toBe(['config' => null, 'fpm_service' => null, 'processes' => []]);
});

/** A native run result: each named step's status and stdout, as Swamp's run data gives them. */
function nativeEnvironmentRun(array $steps): array
{
    return ['steps' => array_map(fn (array|string $step): array => is_string($step) ? ['status' => $step] : $step, $steps),
        'summary' => ['status' => 'unknown', 'succeeded' => 0, 'failed' => 0, 'skipped' => 0, 'failed_steps' => []]];
}

function nativeEnvironmentApply(Operation $operation, string $status, array $steps, ?string $code = null): Operation
{
    return app(NativeInstanceEnvironmentProjection::class)->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'status' => $status,
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'error_code' => $code, 'result' => nativeEnvironmentRun($steps)]);
}

it('builds the public result from the run and records the key names as owned', function (): void {
    $instance = nativeEnvironmentInstance();
    $operation = app(StartInstanceEnvironmentSyncAction::class)->execute($instance);
    $sha = $operation->input['direct_environment']['sha256'];
    nativeEnvironmentApply($operation, 'succeeded', ['Read the key names' => ['status' => 'succeeded', 'stdout' => "#sha {$sha}  .env\nAPI_TOKEN"],
        'Check for foreign keys' => 'succeeded', 'Write .env, .env.testing-0' => 'succeeded', 'Check the written file' => 'succeeded']);
    expect($operation->refresh()->status->value)->toBe('succeeded')
        ->and($operation->result)->toBe(['instance_id' => $instance->id, 'changed' => false, 'keys' => ['API_TOKEN'], 'key_count' => 1,
            'test_database' => null, 'environment_changed' => false, 'testing_status' => null,
            'runtime_refresh' => 'skipped_development', 'restarted_process_ids' => []])
        ->and($instance->refresh()->environment_owned_keys)->toBe(['API_TOKEN']);
});

it('reports a changed .env when its hash before the write differs', function (): void {
    $operation = app(StartInstanceEnvironmentSyncAction::class)->execute(nativeEnvironmentInstance());
    nativeEnvironmentApply($operation, 'succeeded', ['Read the key names' => ['status' => 'succeeded', 'stdout' => 'OLD'], 'Check the written file' => 'succeeded']);
    expect($operation->refresh()->result)->toMatchArray(['changed' => true, 'environment_changed' => true]);
});

it('maps a failed foreign-key check to env.sync_would_drop_keys with the foreign key names', function (): void {
    $instance = nativeEnvironmentInstance();
    $operation = app(StartInstanceEnvironmentSyncAction::class)->execute($instance);
    nativeEnvironmentApply($operation, 'failed', ['Read the key names' => ['status' => 'succeeded', 'stdout' => "#sha 00  .env\nOLD\nZED\nAPI_TOKEN\nFOREIGN"],
        'Check for foreign keys' => 'failed'], 'operation.native_failed');
    expect($operation->refresh()->error_code)->toBe('env.sync_would_drop_keys')
        ->and($operation->result)->toBe(['error_code' => 'env.sync_would_drop_keys', 'foreign_keys' => ['FOREIGN', 'ZED']])
        ->and($instance->refresh()->environment_owned_keys)->toBe(['OLD']);
});

it('maps a failed runtime refresh to env.runtime_refresh_failed', function (): void {
    $operation = app(StartInstanceEnvironmentSyncAction::class)->execute(nativeProductionEnvironmentInstance());
    nativeEnvironmentApply($operation, 'failed', ['Check the written file' => 'succeeded', 'Refresh the runtime-1' => 'failed'], 'operation.native_failed');
    expect($operation->refresh()->error_code)->toBe('env.runtime_refresh_failed');
});

it('pushes .env.testing as its own vault copy, hashed as a whole file', function (): void {
    $instance = nativeEnvironmentInstance();
    $connection = DatabaseConnection::query()->create(['slug' => 'native-env-db', 'driver' => 'mysql', 'owner_instance_id' => $instance->id,
        'node_id' => $instance->node_id, 'host' => '10.44.0.3', 'port' => 3306, 'database' => 'app', 'test_database' => 'app_test',
        'username' => 'app', 'password' => 'synthetic-password']);
    DatabaseConnectionTarget::query()->create(['instance_id' => $instance->id, 'database_connection_id' => $connection->id, 'prefix' => 'DB']);
    $operation = app(StartInstanceEnvironmentSyncAction::class)->execute($instance);
    $puts = collect($this->socket->sent)->where('type', 'vault.put')->keyBy(fn (array $frame): string => $frame['payload']['key']);
    $testing = $puts['instance-environment-testing-'.$operation->id]['payload']['value'];
    expect($testing)->toContain('APP_ENV="testing"')->toContain('app_test')
        ->and($operation->input['direct_environment']['testing'])->toBe(['sha256' => hash('sha256', $testing), 'test_database' => 'app_test'])
        ->and(collect($this->socket->sent)->last()['payload']['inputs']['execution_secrets']['testing'])
        ->toBe(['vault' => 'orbit-control-inputs', 'key' => 'instance-environment-testing-'.$operation->id]);
});
