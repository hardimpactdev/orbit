<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceDeployAction;
use App\Actions\Instances\StartInstanceStepListAction;
use App\Domain\Instances\Environment\InstanceOperationPreflight;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationChildIntent;
use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\DevelopmentDeployStep;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\LifecycleStep;
use App\Domain\Projects\ProjectDevelopmentDeployStepStore;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\InstanceDeployment;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Route;
use Tests\Support\FakeAgentCommandRunner;

function stepListExecutor($test, array $commands = []): array
{
    $instance = dev935_instance();
    $instance->update(['root' => 'nested/app']);
    foreach ($commands as $name => $command) {
        app(ProjectLifecycleStepStore::class)->create($instance->project, LifecyclePhase::Setup, new LifecycleStep($name, $command, 30), null, null);
    }
    $operation = app(StartInstanceStepListAction::class)->execute($instance, LifecyclePhase::Setup, 'operator', null);
    $gateway = Node::query()->create(['name' => 'gateway', 'wireguard_ip' => '10.44.0.1', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    NodeRole::query()->create(['node_id' => $gateway->id, 'role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $services = Node::query()->create(['name' => 'services', 'wireguard_ip' => '10.44.0.17', 'public_ssh_host' => '192.0.2.17', 'user' => 'orbit', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    $services->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $services->id]);
    $test->withServerVariables(['REMOTE_ADDR' => $services->wireguard_ip]);
    $agents = new FakeAgentCommandRunner()->answer('tool.inspect-vp-scope', ['binary' => '/opt/orbit/vite-plus/bin/vp'])
        ->answer('instance.run-lifecycle-step', ['exit_code' => 0, 'timed_out' => false, 'output' => [], 'truncated' => false]);
    app()->instance(AgentCommandRunner::class, $agents);
    $test->postJson('/api/v1/operation-executor/claim', ['kind' => 'instance.run_steps', 'worker' => 'proof'])->assertOk();

    return [$instance, $operation, $agents];
}

function stepListDescriptor($test, Operation $operation, string $key): array
{
    $context = $test->postJson('/api/v1/operation-executor/'.$operation->id.'/heartbeat', ['worker' => 'proof'])->assertOk()->json('data.execution_context.descriptors');

    return array_find($context, fn (array $descriptor): bool => $descriptor['key'] === $key);
}

function stepListRun($test, Operation $operation, array $descriptor, bool $finish = true): array
{
    $path = '/api/v1/operation-executor/'.$operation->id;
    $response = $test->postJson($path.'/run', ['worker' => 'proof', ...array_intersect_key($descriptor, array_flip(['key', 'transport', 'name', 'input']))]);
    $result = $response->assertOk()->json('data');
    if ($finish) {
        $test->postJson($path.'/step', ['worker' => 'proof', 'key' => $descriptor['key'].':finish', 'name' => $descriptor['step'], 'status' => 'succeeded', 'result' => $result])->assertOk();
    }

    return $result;
}

it('requires keyed evidence for an empty setup list before completing its no-op', function (): void {
    [$instance, $operation, $agents] = stepListExecutor($this);
    $path = '/api/v1/operation-executor/'.$operation->id;
    $result = ['instance_id' => $instance->id, 'outcome' => 'succeeded', 'failed_step' => null, 'error_code' => null, 'no_op' => true];
    $this->postJson($path.'/complete', ['worker' => 'proof', 'status' => 'succeeded', 'result' => $result])->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    $receipt = stepListRun($this, $operation, stepListDescriptor($this, $operation, 'lifecycle.receipt'));
    expect($receipt)->toBe($result);
    $this->postJson($path.'/complete', ['worker' => 'proof', 'status' => 'succeeded', 'result' => $receipt])->assertOk();
    expect($agents->calls)->toBe([])->and($instance->refresh()->first_setup_pending)->toBeFalse();
});

it('runs protected stored programs at repository root and reuses a proven reply', function (): void {
    [$instance, $operation, $agents] = stepListExecutor($this, ['install' => 'synthetic-private-command', 'check' => 'check']);
    stepListRun($this, $operation, stepListDescriptor($this, $operation, 'lifecycle.scope'));
    stepListRun($this, $operation, stepListDescriptor($this, $operation, 'budget.setup.install'));
    $install = stepListDescriptor($this, $operation, 'setup.install');
    expect($install['input']['checkout'])->toBe($instance->checkout_path)->and($install['input']['environment']['VP_HOME'])->toBe('/opt/orbit/vite-plus')->and(json_encode($install))->not->toContain('synthetic-private-command');
    stepListRun($this, $operation, $install, false);
    stepListRun($this, $operation, $install);
    stepListRun($this, $operation, stepListDescriptor($this, $operation, 'budget.setup.check'));
    stepListRun($this, $operation, stepListDescriptor($this, $operation, 'setup.check'));
    $receipt = stepListRun($this, $operation, stepListDescriptor($this, $operation, 'lifecycle.receipt'));
    $this->postJson('/api/v1/operation-executor/'.$operation->id.'/complete', ['worker' => 'proof', 'status' => 'succeeded', 'result' => $receipt])->assertOk();
    expect(array_column($agents->calls, 'command'))->toBe(['tool.inspect-vp-scope', 'instance.run-lifecycle-step', 'instance.run-lifecycle-step'])->and(json_encode($operation->refresh()->toArray()))->not->toContain('synthetic-private-command');
});

it('refuses a later step or receipt before earlier effects have proven success', function (string $key): void {
    [, $operation, $agents] = stepListExecutor($this, ['install' => 'install', 'check' => 'check']);
    stepListRun($this, $operation, stepListDescriptor($this, $operation, 'lifecycle.scope'));
    stepListRun($this, $operation, stepListDescriptor($this, $operation, 'budget.setup.install'));
    $descriptor = stepListDescriptor($this, $operation, $key);
    $this->postJson('/api/v1/operation-executor/'.$operation->id.'/run', ['worker' => 'proof', ...array_intersect_key($descriptor, array_flip(['key', 'transport', 'name', 'input']))])->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    expect(array_column($agents->calls, 'command'))->toBe(['tool.inspect-vp-scope']);
})->with(['budget.setup.check', 'lifecycle.receipt']);

it('refuses configuration changes after admission before a Node effect', function (): void {
    [$instance, $operation, $agents] = stepListExecutor($this);
    app(ProjectLifecycleStepStore::class)->create($instance->project, LifecyclePhase::Setup, new LifecycleStep('changed', 'changed', 30), null, null);
    $this->postJson('/api/v1/operation-executor/'.$operation->id.'/heartbeat', ['worker' => 'proof'])->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
    expect($agents->calls)->toBe([]);
});

it('requires preparation and the proven environment write before completing synchronization', function (): void {
    [$instance, , $agents] = stepListExecutor($this);
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
    $write = stepListDescriptor($this, $operation, 'environment.write');
    $this->postJson('/api/v1/operation-executor/'.$operation->id.'/run', ['worker' => 'proof', ...array_intersect_key($write, array_flip(['key', 'transport', 'name', 'input']))])
        ->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    stepListRun($this, $operation, stepListDescriptor($this, $operation, 'environment.prepare'));
    stepListRun($this, $operation, $write, false);
    stepListRun($this, $operation, $write);
    $receipt = stepListRun($this, $operation, stepListDescriptor($this, $operation, 'environment.receipt'));
    $this->postJson('/api/v1/operation-executor/'.$operation->id.'/complete', ['worker' => 'proof', 'status' => 'succeeded', 'result' => $receipt])->assertOk();
    expect($receipt)->toMatchArray(['instance_id' => $instance->id, 'confirmed' => true, 'environment_changed' => true, 'key_count' => 1])
        ->and(array_column($agents->calls, 'command'))->toBe(['instance.write-environment'])
        ->and($agents->calls[0]['input']['content'])->toBe("API_TOKEN=\"synthetic-environment-secret\"\n")
        ->and(json_encode($operation->refresh()->toArray()))->not->toContain('synthetic-environment-secret');
});

it('authorizes parent rollback classification only from a matching failed Node receipt', function (int $exit, int $status): void {
    [$instance, $operation, $agents] = stepListExecutor($this, ['install' => 'synthetic-command']);
    $agents->answer('instance.run-lifecycle-step', ['exit_code' => $exit, 'timed_out' => false, 'output' => [], 'truncated' => false]);
    stepListRun($this, $operation, stepListDescriptor($this, $operation, 'lifecycle.scope'));
    stepListRun($this, $operation, stepListDescriptor($this, $operation, 'budget.setup.install'));
    $receipt = stepListRun($this, $operation, stepListDescriptor($this, $operation, 'setup.install'), false);
    $path = '/api/v1/operation-executor/'.$operation->id;
    $result = [...$receipt, 'instance_id' => $instance->id, 'outcome' => 'missing', 'failed_step' => 'setup.install'];
    $this->postJson($path.'/step', ['worker' => 'proof', 'key' => 'failed', 'name' => 'setup.install', 'status' => 'failed',
        'result' => $result, 'error_code' => 'instance.setup_step_unavailable'])->assertOk();
    $response = $this->postJson($path.'/complete', ['worker' => 'proof', 'status' => 'failed', 'result' => $result,
        'error_code' => 'instance.setup_step_unavailable'])->assertStatus($status);
    if ($status === 409) {
        $response->assertJsonPath('error.code', 'operation.completion_unproven');
    } else {
        expect($operation->refresh()->result['outcome'])->toBe('missing');
    }
})->with([[0, 409], [127, 200]]);

it('proves deadline exhaustion before starting an out-of-budget Project command', function (): void {
    [$instance, $operation, $agents] = stepListExecutor($this, ['install' => 'synthetic-command']);
    stepListRun($this, $operation, stepListDescriptor($this, $operation, 'lifecycle.scope'));
    $operation->update(['started_at' => now()->subSeconds(551)]);
    $descriptor = stepListDescriptor($this, $operation, 'budget.setup.install');
    $result = stepListRun($this, $operation, $descriptor, false);
    expect($result)->toMatchArray(['budget_seconds' => 0, 'error_code' => 'command.deadline_exceeded', 'outcome' => 'deadline']);
    $result = [...$result, 'instance_id' => $instance->id, 'failed_step' => $descriptor['step']];
    $path = '/api/v1/operation-executor/'.$operation->id;
    $this->postJson($path.'/step', ['worker' => 'proof', 'key' => 'deadline', 'name' => $descriptor['step'], 'status' => 'failed',
        'result' => $result, 'error_code' => 'command.deadline_exceeded'])->assertOk();
    $this->postJson($path.'/complete', ['worker' => 'proof', 'status' => 'failed', 'result' => $result,
        'error_code' => 'command.deadline_exceeded'])->assertOk();
    expect(array_column($agents->calls, 'command'))->toBe(['tool.inspect-vp-scope']);
});

it('keeps a budget-cut timeout distinct from a command failed at its own timeout', function (): void {
    [$instance, $operation, $agents] = stepListExecutor($this, ['install' => 'synthetic-command']);
    $agents->answer('instance.run-lifecycle-step', ['exit_code' => 143, 'timed_out' => true, 'output' => [], 'truncated' => false]);
    stepListRun($this, $operation, stepListDescriptor($this, $operation, 'lifecycle.scope'));
    $operation->update(['started_at' => now()->subSeconds(540)]);
    stepListRun($this, $operation, stepListDescriptor($this, $operation, 'budget.setup.install'));
    $descriptor = stepListDescriptor($this, $operation, 'setup.install');
    expect($descriptor['input']['timeout_seconds'])->toBeLessThan(30);
    $receipt = stepListRun($this, $operation, $descriptor, false);
    $result = [...$receipt, 'outcome' => 'deadline', 'instance_id' => $instance->id, 'failed_step' => 'setup.install'];
    $path = '/api/v1/operation-executor/'.$operation->id;
    $this->postJson($path.'/step', ['worker' => 'proof', 'key' => 'deadline', 'name' => 'setup.install', 'status' => 'failed',
        'result' => $result, 'error_code' => 'command.deadline_exceeded'])->assertOk();
    $this->postJson($path.'/complete', ['worker' => 'proof', 'status' => 'failed', 'result' => $result,
        'error_code' => 'command.deadline_exceeded'])->assertOk();
});

it('adopts a target environment receipt through the deploy transport without resolving protected content again', function (): void {
    [$instance, , $agents] = stepListExecutor($this);
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
    $agents->answer('agent.effect-receipt', ['command' => 'instance.write-environment', 'effect_key' => $key,
        'state' => 'succeeded', 'sanitized' => true, 'exit_code' => 0,
        'result' => ['ok' => true, 'data' => ['state' => 'changed', 'installed' => true]],
        'result_digest' => str_repeat('a', 64), 'finished_at' => '2026-10-28T22:00:00Z']);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'instance.sync_environment', 'worker' => 'proof'])->assertOk();
    stepListRun($this, $operation, stepListDescriptor($this, $operation, 'environment.prepare'));
    $write = stepListDescriptor($this, $operation, 'environment.write');

    $this->postJson($path.'/run', ['worker' => 'proof', ...array_intersect_key($write, array_flip(['key', 'transport', 'name', 'input']))])
        ->assertStatus(502)->assertJsonPath('error.code', 'operation.execution_failed');
    expect($operation->refresh()->state['executor_runs']['environment.write']['result'])->toBeNull();
    $result = $this->getJson($path.'/receipts/environment.write')->assertOk()->assertJsonPath('data.state', 'succeeded')
        ->assertJsonPath('data.result', ['state' => 'changed', 'confirmed' => true, 'changed' => true])->json('data.result');
    $this->getJson($path.'/receipts/environment.write')->assertOk()->assertJsonPath('data.result', $result);

    expect($agents->commands())->toBe(['instance.write-environment', 'agent.effect-receipt']);
    expect($agents->calls[0]['input']['effect_key'])->toBe($key);
    expect($operation->refresh()->state['executor_runs']['environment.write']['input']['content'])->toBe(['$secret' => 'instance.environment_sync', 'id' => $instance->id]);
    expect(json_encode($operation->toArray()))->not->toContain('synthetic-rotating-environment');
});

it('completes a development deployment with a proven best-effort warning without production-only children', function (): void {
    [$instance, $setup, $agents] = stepListExecutor($this);
    $receipt = stepListRun($this, $setup, stepListDescriptor($this, $setup, 'lifecycle.receipt'));
    $this->postJson('/api/v1/operation-executor/'.$setup->id.'/complete', ['worker' => 'proof', 'status' => 'succeeded', 'result' => $receipt])->assertOk();
    $commit = str_repeat('d', 40);
    $agents->answer('instance.resolve-development-target', ['commit' => $commit])
        ->answer('instance.checkout-development-default', ['commit' => $commit]);
    app(ProjectDevelopmentDeployStepStore::class)->create($instance->project, new DevelopmentDeployStep('warm', 'false', required: false), null, null);
    $agents->answer('instance.run-development-step', ['exit_code' => 42, 'timed_out' => false, 'output' => [], 'truncated' => false]);
    $operation = app(StartInstanceDeployAction::class)->execute($instance, 'operator', null);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'instance.deploy', 'worker' => 'proof'])->assertOk();
    stepListRun($this, $operation, stepListDescriptor($this, $operation, 'source.resolve'));
    stepListRun($this, $operation, stepListDescriptor($this, $operation, 'source.checkout'));
    stepListRun($this, $operation, stepListDescriptor($this, $operation, 'budget.before_activation.warm'));
    $descriptor = stepListDescriptor($this, $operation, 'before_activation.warm');
    $failed = stepListRun($this, $operation, $descriptor, false);
    $this->postJson('/api/v1/operation-executor/'.$operation->id.'/step', ['worker' => 'proof', 'key' => 'warm:failed',
        'name' => $descriptor['step'], 'status' => 'failed', 'result' => $failed, 'error_code' => 'deployment.step_failed'])->assertOk();
    $receipt = stepListRun($this, $operation, stepListDescriptor($this, $operation, 'verification'));
    $this->postJson('/api/v1/operation-executor/'.$operation->id.'/complete', ['worker' => 'proof', 'status' => 'succeeded', 'result' => $receipt])->assertOk();
    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded)
        ->and($instance->refresh()->seed_commit)->toBe($commit)
        ->and(InstanceDeployment::query()->sole()->status)->toBe('succeeded');
    $events = InstanceDeployment::query()->sole()->events;
    $output = implode('', array_map(static fn (array $event): string => isset($event['value_base64']) ? base64_decode($event['value_base64']) : '', $events));
    expect($output)->toContain('Best-effort step failed (exit 42): warm');
});

it('hydrates a protected Route URL boolean without persisting environment material or masking numeric receipts', function (bool $stored): void {
    [$instance, $setup, $agents] = stepListExecutor($this);
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
    stepListRun($this, $operation, stepListDescriptor($this, $operation, 'environment.prepare'));
    $descriptors = $this->postJson('/api/v1/operation-executor/'.$operation->id.'/heartbeat', ['worker' => 'proof'])->assertOk()->json('data.execution_context.descriptors');
    $url = array_find($descriptors, static fn (array $descriptor): bool => str_starts_with($descriptor['key'], 'route.url.'));
    stepListRun($this, $operation, $url);
    $call = array_find($agents->calls, static fn (array $call): bool => $call['command'] === 'instance.write-development-url');
    expect($call['input']['app_name_stored'])->toBe($stored);
    expect(json_encode($operation->refresh()->toArray()))->not->toContain('Synthetic stored name', 'APP_KEY=');
})->with([false, true]);
