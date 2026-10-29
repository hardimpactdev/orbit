<?php

declare(strict_types=1);

use App\Actions\Instances\ImportInstanceEnvironmentAction;
use App\Actions\Instances\UpdateInstanceEnvironmentAction;
use App\Domain\Instances\Environment\EnvironmentSyncMaterial;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\Environment\InstanceEnvironmentReader;
use App\Domain\Instances\Environment\InstanceEnvironmentWriter;
use App\Domain\Instances\Environment\InstanceEnvironmentWriteResult;
use App\Domain\Instances\Environment\InstanceOperationPreflight;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\EffectReceiptMetadata;
use App\Domain\Operations\OperationChildIntent;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Route;
use Tests\Support\FakeAgentCommandRunner;

function environmentChildExecutor($test): array
{
    $instance = dev935_instance();
    $instance->update(['root' => 'nested/app', 'source_is_laravel' => false, 'provisioning_step' => 'active']);
    $gateway = Node::query()->create(['name' => 'gateway', 'wireguard_ip' => '10.44.0.1', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    NodeRole::query()->create(['node_id' => $gateway->id, 'role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $services = Node::query()->create(['name' => 'services', 'wireguard_ip' => '10.44.0.17', 'public_ssh_host' => '192.0.2.17', 'user' => 'orbit', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    $services->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $services->id]);
    $test->withServerVariables(['REMOTE_ADDR' => $services->wireguard_ip]);
    $agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);

    return [$instance, null, $agents];
}

function environmentChildDescriptor($test, Operation $operation, string $key): array
{
    $context = $test->postJson('/api/v1/operation-executor/'.$operation->id.'/heartbeat', ['worker' => 'proof'])->assertOk()->json('data.execution_context.descriptors');

    return array_find($context, fn (array $descriptor): bool => $descriptor['key'] === $key);
}

function environmentChildRun($test, Operation $operation, array $descriptor, bool $finish = true): array
{
    $path = '/api/v1/operation-executor/'.$operation->id;
    $response = $test->postJson($path.'/run', ['worker' => 'proof', ...array_intersect_key($descriptor, array_flip(['key', 'transport', 'name', 'input']))]);
    $result = $response->assertOk()->json('data');
    if ($finish) {
        $test->postJson($path.'/step', ['worker' => 'proof', 'key' => $descriptor['key'].':finish', 'name' => $descriptor['step'], 'status' => 'succeeded', 'result' => $result])->assertOk();
    }

    return $result;
}

it('requires preparation and the proven environment write before completing synchronization', function (): void {
    [$instance, , $agents] = environmentChildExecutor($this);
    $instance->update(['provisioning_step' => 'active', 'source_is_laravel' => false]);
    $instance->environmentValues()->create(['env_key' => 'API_TOKEN', 'env_value' => 'synthetic-environment-secret']);
    $preflight = Mockery::mock(InstanceOperationPreflight::class);
    $preflight->shouldReceive('readWritableEnvironment')->andReturn('API_TOKEN=old');
    app()->instance(InstanceOperationPreflight::class, $preflight);
    $agents->answer('instance.write-environment', ['state' => 'changed', 'installed' => true]);
    $operation = Operation::query()->create(['kind' => 'instance.sync_environment', 'target_type' => 'instance',
        'target_id' => $instance->id, 'node_id' => $instance->node_id, 'status' => OperationStatus::Queued,
        'worker' => null, 'sequence' => 1, 'input' => ['instance_id' => $instance->id]]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'instance.sync_environment', 'worker' => 'proof'])->assertOk();
    $write = environmentChildDescriptor($this, $operation, 'environment.write');
    $this->postJson('/api/v1/operation-executor/'.$operation->id.'/run', ['worker' => 'proof', ...array_intersect_key($write, array_flip(['key', 'transport', 'name', 'input']))])
        ->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    environmentChildRun($this, $operation, environmentChildDescriptor($this, $operation, 'environment.prepare'));
    environmentChildRun($this, $operation, $write, false);
    environmentChildRun($this, $operation, $write);
    $receipt = environmentChildRun($this, $operation, environmentChildDescriptor($this, $operation, 'environment.receipt'));
    $this->postJson('/api/v1/operation-executor/'.$operation->id.'/complete', ['worker' => 'proof', 'status' => 'succeeded', 'result' => $receipt])->assertOk();
    expect($receipt)->toMatchArray(['instance_id' => $instance->id, 'confirmed' => true, 'environment_changed' => true, 'key_count' => 1])
        ->and(array_column($agents->calls, 'command'))->toBe(['instance.write-environment'])
        ->and($agents->calls[0]['input']['content'])->toBe("API_TOKEN=\"synthetic-environment-secret\"\n")
        ->and(json_encode($operation->refresh()->toArray()))->not->toContain('synthetic-environment-secret');
});

it('adopts a target environment receipt through the environment transport without resolving protected content again', function (bool $wrongIdentity, bool $cancelled): void {
    [$instance, , $agents] = environmentChildExecutor($this);
    $instance->update(['provisioning_step' => 'active', 'source_is_laravel' => false]);
    $instance->environmentValues()->create(['env_key' => 'API_TOKEN', 'env_value' => 'synthetic-rotating-environment']);
    $preflight = Mockery::mock(InstanceOperationPreflight::class);
    $preflight->shouldReceive('readWritableEnvironment')->once()->andReturn('API_TOKEN=old');
    app()->instance(InstanceOperationPreflight::class, $preflight);
    $agents->answer('instance.write-environment', static fn (): array => throw new RuntimeException('Lost write reply'));
    $operation = Operation::query()->create(['kind' => 'instance.sync_environment', 'target_type' => 'instance',
        'target_id' => $instance->id, 'node_id' => $instance->node_id, 'status' => OperationStatus::Queued,
        'sequence' => 1, 'input' => ['instance_id' => $instance->id]]);
    $path = '/api/v1/operation-executor/'.$operation->id;
    $key = 'op-'.$operation->id.'.environment.write';
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'instance.sync_environment', 'worker' => 'proof'])->assertOk();
    environmentChildRun($this, $operation, environmentChildDescriptor($this, $operation, 'environment.prepare'));
    $write = environmentChildDescriptor($this, $operation, 'environment.write');

    $this->postJson($path.'/run', ['worker' => 'proof', ...array_intersect_key($write, array_flip(['key', 'transport', 'name', 'input']))])
        ->assertConflict()->assertJsonPath('error.code', 'operation.execution_uncertain');
    expect($operation->refresh()->state['executor_runs']['environment.write']['result'])->toBeNull();
    if ($cancelled) {
        $operation->update(['cancel_requested_at' => now()]);
        $this->postJson($path.'/run', ['worker' => 'proof', ...array_intersect_key($write, array_flip(['key', 'transport', 'name', 'input']))])
            ->assertConflict()->assertJsonPath('error.code', 'operation.execution_uncertain');
        expect($operation->refresh()->state['executor_runs']['environment.write']['result'])->toBeNull();
    }
    $identity = $operation->state['executor_effect_identity'];
    $metadata = $agents->calls[0]['input'][EffectReceiptMetadata::InputKey]->envelope();
    expect($metadata['nonce'])->toBe($identity['nonce'])->and($metadata['created_at'])->toBe($identity['created_at']);
    $target = ['command' => 'instance.write-environment', 'effect_key' => $key,
        'effect_nonce' => $identity['nonce'], 'effect_created_at' => $identity['created_at'],
        'state' => 'succeeded', 'sanitized' => true, 'exit_code' => 0,
        'result' => ['ok' => true, 'data' => ['state' => 'changed', 'installed' => true]],
        'result_digest' => str_repeat('a', 64), 'finished_at' => '2026-10-28T22:00:00Z'];
    if ($wrongIdentity) {
        $agents->answer('agent.effect-receipt', [...$target, 'effect_nonce' => '00000000-0000-4000-8000-000000000000']);
        $this->getJson($path.'/receipts/environment.write')->assertConflict()->assertJsonPath('error.code', 'operation.execution_uncertain');
        expect($operation->refresh()->state['executor_runs']['environment.write']['result'])->toBeNull();
    }
    $agents->answer('agent.effect-receipt', $target);
    $result = $this->getJson($path.'/receipts/environment.write')->assertOk()->assertJsonPath('data.state', 'succeeded')->assertJsonMissingPath('data.effect_nonce')->assertJsonMissingPath('data.effect_created_at')
        ->assertJsonPath('data.result', ['state' => 'changed', 'confirmed' => true, 'changed' => true])->json('data.result');
    $this->getJson($path.'/receipts/environment.write')->assertOk()->assertJsonPath('data.result', $result);

    expect($agents->commands())->toBe($wrongIdentity ? ['instance.write-environment', 'agent.effect-receipt', 'agent.effect-receipt'] : ['instance.write-environment', 'agent.effect-receipt']);
    expect($agents->calls[0]['input']['effect_key'])->toBe($key);
    expect($operation->refresh()->state['executor_runs']['environment.write']['input']['content'])->toBe(['$secret' => 'instance.environment_sync', 'id' => $instance->id]);
    expect(json_encode($operation->toArray()))->not->toContain('synthetic-rotating-environment');
})->with([false, true])->with([false, true]);

it('hydrates a protected Route URL boolean without persisting environment material or masking numeric receipts', function (bool $stored): void {
    [$instance, $setup, $agents] = environmentChildExecutor($this);
    $instance->update(['selected_php_version' => '8.5', 'provisioning_step' => 'active', 'source_is_laravel' => true]);
    if ($stored) {
        $instance->environmentValues()->create(['env_key' => 'APP_NAME', 'env_value' => 'Synthetic stored name']);
    }
    $route = Route::query()->create(['project_id' => $instance->project_id, 'node_id' => $instance->node_id,
        'domain' => 'url.example.test', 'web_root' => 'other/public', 'provenance' => 'explicit', 'publication' => 'public', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);
    $parent = Operation::query()->create(['kind' => 'route.update', 'target_type' => 'route', 'target_id' => $route->id,
        'node_id' => $instance->node_id, 'status' => 'running', 'sequence' => 1,
        'input' => ['route_id' => $route->id, 'target_instance_ids' => [$instance->id]]]);
    $operation = Operation::query()->create(['kind' => 'instance.sync_environment', 'target_type' => 'instance',
        'target_id' => $instance->id, 'node_id' => $instance->node_id, 'status' => 'queued', 'sequence' => 1,
        'input' => ['instance_id' => $instance->id, 'route_domain' => 'authoritative']]);
    // Exercise the owning family hydration path with the shared kit's stamped admission identity.
    $operation->forceFill(['parent_operation_id' => $parent->id, 'child_key' => 'synchronize-web-root-urls.rollback.'.$instance->id]);
    $identity = ['parent_operation_id' => $parent->id, 'key' => $operation->child_key, 'kind' => $operation->kind,
        'node_id' => $operation->node_id, 'target_type' => 'instance', 'target_id' => $instance->id,
        'input' => $operation->input, 'run_constraints' => []];
    $snapshot = ['intent' => [], 'admitted' => $identity];
    $operation->forceFill(['child_admission' => $snapshot, 'child_fingerprint' => OperationChildIntent::fingerprint($snapshot)])->save();
    $agents->answer('instance.write-development-url', ['written' => true]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'instance.sync_environment', 'worker' => 'proof'])->assertOk();
    environmentChildRun($this, $operation, environmentChildDescriptor($this, $operation, 'environment.prepare'));
    $descriptors = $this->postJson('/api/v1/operation-executor/'.$operation->id.'/heartbeat', ['worker' => 'proof'])->assertOk()->json('data.execution_context.descriptors');
    $url = array_find($descriptors, static fn (array $descriptor): bool => str_starts_with($descriptor['key'], 'route.url.'));
    environmentChildRun($this, $operation, $url);
    $call = array_find($agents->calls, static fn (array $call): bool => $call['command'] === 'instance.write-development-url');
    expect($call['input']['app_name_stored'])->toBe($stored);
    expect(json_encode($operation->refresh()->toArray()))->not->toContain('Synthetic stored name', 'APP_KEY=');
})->with([false, true]);

it('admits the exact Route-owned environment child and replays it after the association is removed', function (): void {
    [$instance, , $agents] = environmentChildExecutor($this);
    $instance->environmentValues()->create(['env_key' => 'APP_URL', 'env_value' => 'https://fixed.example.test']);
    $route = Route::query()->create(['project_id' => $instance->project_id, 'node_id' => $instance->node_id,
        'domain' => 'removed.example.test', 'provenance' => 'explicit', 'publication' => 'public', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);
    $route->targets()->delete();
    $key = 'environment-synchronized.'.$instance->id;
    $intent = ['key' => $key, 'kind' => 'instance.sync_environment', 'node_id' => $instance->node_id,
        'input' => ['instance_id' => $instance->id, 'route_domain' => 'authoritative']];
    $parent = Operation::query()->create(['kind' => 'route.target_set', 'target_type' => 'route', 'target_id' => $route->id,
        'node_id' => $instance->node_id, 'status' => 'queued', 'sequence' => 1,
        'input' => ['route_id' => $route->id, 'target_instance_ids' => [], 'previous_target_instance_ids' => [$instance->id]],
        'state' => ['route_active_stage' => 'environment-synchronized', 'route_plan' => ['mode' => 'state_only',
            'node_ids' => [$instance->node_id], 'stages' => ['environment-synchronized' => [$intent]]]]]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $parent->kind, 'worker' => 'parent'])->assertOk()->assertJsonPath('data.id', $parent->id);
    $path = '/api/v1/operation-executor/'.$parent->id.'/children';
    $childId = $this->postJson($path, ['worker' => 'parent', 'key' => $key])->assertAccepted()->json('data.id');
    $this->postJson($path, ['worker' => 'parent', 'key' => $key])->assertOk()->assertJsonPath('data.id', $childId);
    $this->postJson($path, ['worker' => 'parent', 'key' => $key, 'input' => ['instance_id' => $instance->id + 1, 'route_domain' => 'authoritative']])
        ->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');
    $child = Operation::query()->findOrFail($childId);
    expect($child->parent_operation_id)->toBe($parent->id)->and($child->node_id)->toBe($instance->node_id)
        ->and($child->input)->toBe($intent['input'])->and($child->child_key)->toBe($key);
    expect(app(EnvironmentSyncMaterial::class)->context($child)->routeDomain)->toBeNull();
    $this->postJson('/api/v1/operation-executor/'.$parent->id.'/wait', ['worker' => 'parent', 'keys' => [$key]])->assertOk();
    $preflight = Mockery::mock(InstanceOperationPreflight::class);
    $preflight->shouldReceive('assertEnvironmentWritable')->once();
    app()->instance(InstanceOperationPreflight::class, $preflight);
    $agents->answer('instance.write-environment', ['state' => 'changed', 'installed' => true]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $child->kind, 'worker' => 'proof'])->assertOk()->assertJsonPath('data.id', $childId);
    foreach (['environment.prepare', 'environment.write'] as $runKey) {
        environmentChildRun($this, $child, environmentChildDescriptor($this, $child, $runKey));
    }
    $receipt = environmentChildRun($this, $child, environmentChildDescriptor($this, $child, 'environment.receipt'));
    $this->postJson('/api/v1/operation-executor/'.$child->id.'/complete', ['worker' => 'proof', 'status' => 'succeeded', 'result' => $receipt])->assertOk();
    expect($instance->refresh()->environment_owned_keys)->toBe(['APP_URL']);
    expect(app(OperationChildren::class)->requireSucceeded($parent->refresh(), $key)->id)->toBe($childId);

});

it('requires the managed testing write receipt and keeps tracked testing files unchanged', function (): void {
    [$instance, , $agents] = environmentChildExecutor($this);
    $instance->environmentValues()->create(['env_key' => 'API_TOKEN', 'env_value' => 'synthetic-testing-source']);
    $connection = DatabaseConnection::query()->create(['slug' => 'environment-child', 'driver' => 'sqlite',
        'path' => ':memory:', 'owner_instance_id' => $instance->id, 'test_database' => ':memory:']);
    DatabaseConnectionTarget::query()->create(['instance_id' => $instance->id, 'database_connection_id' => $connection->id, 'prefix' => 'DB']);
    $preflight = Mockery::mock(InstanceOperationPreflight::class);
    $preflight->shouldReceive('readWritableEnvironment')->once()->andReturn('API_TOKEN=old');
    app()->instance(InstanceOperationPreflight::class, $preflight);
    $agents->answer('instance.write-environment', fn (array $input): array => ['state' => $input['file'] === '.env' ? 'unchanged' : 'tracked', 'installed' => false]);
    $operation = Operation::query()->create(['kind' => 'instance.sync_environment', 'target_type' => 'instance',
        'target_id' => $instance->id, 'node_id' => $instance->node_id, 'status' => 'queued', 'sequence' => 1, 'input' => ['instance_id' => $instance->id]]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $operation->kind, 'worker' => 'proof'])->assertOk();
    environmentChildRun($this, $operation, environmentChildDescriptor($this, $operation, 'environment.prepare'));
    environmentChildRun($this, $operation, environmentChildDescriptor($this, $operation, 'environment.write'));
    $receipt = environmentChildDescriptor($this, $operation, 'environment.receipt');
    $this->postJson('/api/v1/operation-executor/'.$operation->id.'/run', ['worker' => 'proof', ...array_intersect_key($receipt, array_flip(['key', 'transport', 'name', 'input']))])
        ->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    environmentChildRun($this, $operation, environmentChildDescriptor($this, $operation, 'environment.testing'));
    $result = environmentChildRun($this, $operation, $receipt);
    expect($result['changed'])->toBeFalse()->and($result['testing_status'])->toBe('skipped_tracked');
    expect($agents->calls[1]['input']['mode'])->toBe('merge')->and($agents->calls[1]['input']['file'])->toBe('.env.testing')
        ->and($agents->calls[1]['input']['managed_keys'])->toContain('DB_DATABASE')
        ->and(json_encode($operation->refresh()->toArray()))->not->toContain('synthetic-testing-source');
});

it('preserves confirmed safe refusals and unconfirmed environment outcomes', function (string $state, bool $installed, string $code): void {
    [$instance, , $agents] = environmentChildExecutor($this);
    $instance->environmentValues()->create(['env_key' => 'API_TOKEN', 'env_value' => 'synthetic-refusal-source']);
    $preflight = Mockery::mock(InstanceOperationPreflight::class);
    $preflight->shouldReceive('readWritableEnvironment')->once()->andReturn('API_TOKEN=old');
    app()->instance(InstanceOperationPreflight::class, $preflight);
    $agents->answer('instance.write-environment', ['state' => $state, 'installed' => $installed]);
    $operation = Operation::query()->create(['kind' => 'instance.sync_environment', 'target_type' => 'instance',
        'target_id' => $instance->id, 'node_id' => $instance->node_id, 'status' => 'queued', 'sequence' => 1, 'input' => ['instance_id' => $instance->id]]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $operation->kind, 'worker' => 'proof'])->assertOk();
    environmentChildRun($this, $operation, environmentChildDescriptor($this, $operation, 'environment.prepare'));
    $write = environmentChildDescriptor($this, $operation, 'environment.write');
    $result = environmentChildRun($this, $operation, $write, false);
    expect($result)->toBe(['error_code' => $code])->and($instance->refresh()->environment_owned_keys)->toBeNull();
})->with([
    ['refused', false, 'env.write_failed'],
    ['failed', false, 'env.write_failed'],
    ['failed', true, 'env.sync_unconfirmed'],
    ['tracked', false, 'env.sync_unconfirmed'],
]);

final class EnvironmentChildSerializationLock implements InstanceEnvironmentOperationLock
{
    public int $depth = 0;

    public ?Closure $beforeOuter = null;

    public function run(array $instanceIds, Closure $operation): mixed
    {
        if ($this->depth === 0 && $this->beforeOuter !== null) {
            $before = $this->beforeOuter;
            $this->beforeOuter = null;
            $before();
        }
        $this->depth++;
        try {
            return $operation();
        } finally {
            $this->depth--;
        }
    }
}

it('serializes environment hydration and dispatch after a concurrent stored change', function (string $change): void {
    [$instance, , $agents] = environmentChildExecutor($this);
    $instance->environmentValues()->create(['env_key' => 'API_TOKEN', 'env_value' => 'synthetic-original']);
    $locks = new EnvironmentChildSerializationLock;
    app()->instance(InstanceEnvironmentOperationLock::class, $locks);
    $preflight = Mockery::mock(InstanceOperationPreflight::class);
    $preflight->shouldReceive('readWritableEnvironment')->once()->andReturnUsing(function () use ($locks): string {
        expect($locks->depth)->toBeGreaterThan(0);

        return 'API_TOKEN=synthetic-original';
    });
    if ($change === 'import') {
        $preflight->shouldReceive('assertEnvironmentReadable')->once();
        $reader = Mockery::mock(InstanceEnvironmentReader::class);
        $reader->shouldReceive('read')->once()->andReturn("IMPORTED=synthetic-imported\n");
        app()->instance(InstanceEnvironmentReader::class, $reader);
    }
    app()->instance(InstanceOperationPreflight::class, $preflight);
    $agents->answer('instance.write-environment', function () use ($locks): array {
        expect($locks->depth)->toBeGreaterThan(0);

        return ['state' => 'changed', 'installed' => true];
    });
    $operation = Operation::query()->create(['kind' => 'instance.sync_environment', 'target_type' => 'instance',
        'target_id' => $instance->id, 'node_id' => $instance->node_id, 'status' => 'queued', 'sequence' => 1,
        'input' => ['instance_id' => $instance->id]]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $operation->kind, 'worker' => 'proof'])->assertOk();
    environmentChildRun($this, $operation, environmentChildDescriptor($this, $operation, 'environment.prepare'));
    $locks->beforeOuter = function () use ($instance, $change): void {
        if ($change === 'import') {
            app(ImportInstanceEnvironmentAction::class)->execute($instance, false);
        } else {
            app(UpdateInstanceEnvironmentAction::class)->execute($instance, 'API_TOKEN', 'synthetic-updated');
        }
    };
    environmentChildRun($this, $operation, environmentChildDescriptor($this, $operation, 'environment.write'));
    $expected = $change === 'import' ? "API_TOKEN=\"synthetic-original\"\nIMPORTED=\"synthetic-imported\"\n" : "API_TOKEN=\"synthetic-updated\"\n";
    expect($agents->calls[0]['input']['content'])->toBe($expected);
    expect($instance->refresh()->environment_owned_keys)->toBe($change === 'import' ? ['API_TOKEN', 'IMPORTED'] : ['API_TOKEN']);
    expect($locks->depth)->toBe(0)->and(json_encode($operation->refresh()->state))->not->toContain('synthetic-original', 'synthetic-updated', 'synthetic-imported');
})->with(['update', 'import']);

it('refuses public synchronous env sync while a deploy or rollback is open', function (string $kind, string $status): void {
    [$instance] = environmentChildExecutor($this);
    Node::query()->where('name', 'services')->sole()->accessibleNodes()->attach($instance->node_id);
    Operation::query()->create(['kind' => $kind, 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $instance->node_id, 'status' => $status, 'sequence' => 1, 'input' => ['instance_id' => $instance->id]]);
    $preflight = Mockery::mock(InstanceOperationPreflight::class);
    $preflight->shouldNotReceive('readWritableEnvironment', 'assertEnvironmentWritable');
    app()->instance(InstanceOperationPreflight::class, $preflight);
    $this->call('POST', '/api/v1/instances/'.$instance->id.'/environment/sync', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')
        ->assertConflict()->assertJsonPath('error.code', 'env.operation_busy');
})->with(['instance.deploy', 'instance.rollback'])->with(['queued', 'running', 'waiting']);

it('checks the synchronous env sync deploy guard after acquiring the environment lock', function (): void {
    [$instance] = environmentChildExecutor($this);
    Node::query()->where('name', 'services')->sole()->accessibleNodes()->attach($instance->node_id);
    $locks = new EnvironmentChildSerializationLock;
    $locks->beforeOuter = function () use ($instance): void {
        Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $instance->id,
            'node_id' => $instance->node_id, 'status' => 'running', 'sequence' => 1, 'input' => ['instance_id' => $instance->id]]);
    };
    app()->instance(InstanceEnvironmentOperationLock::class, $locks);
    $this->call('POST', '/api/v1/instances/'.$instance->id.'/environment/sync', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')
        ->assertConflict()->assertJsonPath('error.code', 'env.operation_busy');
    expect($locks->depth)->toBe(0);
});

it('keeps public env sync synchronous after the deployment has settled', function (string $status): void {
    [$instance] = environmentChildExecutor($this);
    Node::query()->where('name', 'services')->sole()->accessibleNodes()->attach($instance->node_id);
    $instance->environmentValues()->create(['env_key' => 'API_TOKEN', 'env_value' => 'synthetic-settled']);
    Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $instance->node_id, 'status' => $status, 'sequence' => 1, 'input' => ['instance_id' => $instance->id]]);
    $preflight = Mockery::mock(InstanceOperationPreflight::class);
    $preflight->shouldReceive('readWritableEnvironment')->once()->andReturn('API_TOKEN=old');
    app()->instance(InstanceOperationPreflight::class, $preflight);
    $writer = Mockery::mock(InstanceEnvironmentWriter::class);
    $writer->shouldReceive('write')->once()->andReturn(InstanceEnvironmentWriteResult::unchanged());
    app()->instance(InstanceEnvironmentWriter::class, $writer);
    $this->call('POST', '/api/v1/instances/'.$instance->id.'/environment/sync', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertOk()->assertJsonPath('data.changed', false);
    expect(Operation::query()->count())->toBe(1);
})->with(['succeeded', 'failed', 'cancelled']);
