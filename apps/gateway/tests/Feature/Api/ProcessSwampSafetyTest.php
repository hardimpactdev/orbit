<?php

declare(strict_types=1);

use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;

beforeEach(function (): void {
    $this->node = $this->markAsGateway(Node::query()->create([
        'name' => 'executor-safety', 'status' => 'active', 'platform' => 'linux',
        'user' => 'orbit', 'wireguard_ip' => '10.44.0.3', 'public_ssh_host' => '192.0.2.3',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->process = Process::query()->create([
        'owner_type' => Node::class, 'owner_id' => $this->node->id,
        'name' => 'swamp-serve', 'runtime' => 'systemd',
        'runtime_config' => ['command' => ['/usr/bin/swamp', 'serve']],
        'desired_state' => 'running', 'status' => 'active', 'restart_policy' => 'always',
    ]);
    $this->runtime = Mockery::mock(ProcessRuntimeManager::class);
    $this->runtime->shouldReceive('assertCanStart')->andReturnNull();
    app()->instance(ProcessRuntimeManager::class, $this->runtime);
});

it('runs executor-host mutations synchronously with the original HTTP responses', function (string $peer, string $verb): void {
    config($peer === 'services'
        ? ['dependency_detection.services_node_id' => (string) $this->node->id]
        : ['operation_executor.worker_node_ids' => [$this->node->id]]);
    $this->process->update(['name' => $peer === 'worker' ? 'renamed-executor-dependency' : 'swamp-serve']);
    $this->runtime->shouldReceive('status')->andReturn('active');
    match ($verb) {
        'create', 'update' => $this->runtime->shouldReceive('converge')->once(),
        'destroy' => $this->runtime->shouldReceive('remove')->once(),
        'start' => $this->runtime->shouldReceive('start')->once(),
        'stop' => $this->runtime->shouldReceive('stop')->once(),
        'restart' => $this->runtime->shouldReceive('restart')->once(),
    };
    $path = '/api/v1/processes/'.$this->process->id;

    $response = match ($verb) {
        'create' => $this->postJson('/api/v1/processes', [
            'target_type' => 'node', 'target_id' => $this->node->id,
            'name' => 'another-dependency', 'runtime' => 'systemd', 'command' => ['/bin/sleep', '300'],
        ]),
        'update' => $this->patchJson($path, ['command' => ['/bin/sleep', '300']]),
        'destroy' => $this->deleteJson($path),
        default => $this->postJson($path.'/'.$verb),
    };

    $verb === 'create' ? $response->assertCreated() : $response->assertOk();
    $response->assertJsonPath('data.target_type', 'node')->assertJsonPath('data.target_id', $this->node->id)->assertJsonMissingPath('data.kind');
    if ($verb === 'destroy') {
        $response->assertJsonPath('data.runtime_status', 'absent');
        $this->assertModelMissing($this->process);
    } elseif ($verb === 'update') {
        expect($this->process->refresh()->runtime_config['command'])->toBe(['/bin/sleep', '300']);
    } elseif ($verb !== 'create') {
        expect($this->process->refresh()->desired_state->value)->toBe($verb === 'stop' ? 'stopped' : 'running');
    }
    $this->assertDatabaseCount('processes', match ($verb) {
        'create' => 2, 'destroy' => 0, default => 1
    });
    $this->assertDatabaseCount('operations', 0);
})->with(['services', 'worker'])->with(['create', 'update', 'start', 'stop', 'restart', 'destroy']);

it('stops and starts serve through HTTP without a Swamp worker', function (): void {
    config(['dependency_detection.services_node_id' => $this->node->id]);
    $this->runtime->shouldReceive('stop')->once()->withArgs(fn (Process $process): bool => $process->id === $this->process->id);
    $this->runtime->shouldReceive('start')->once()->withArgs(fn (Process $process, bool $explicit): bool => $process->id === $this->process->id && $explicit);
    $this->runtime->shouldReceive('status')->andReturnUsing(fn (Process $process): string => $process->desired_state->value === 'running' ? 'active' : 'inactive');

    $this->postJson('/api/v1/processes/'.$this->process->id.'/stop')->assertOk()
        ->assertJsonPath('data.id', $this->process->id)->assertJsonPath('data.desired_state', 'stopped')->assertJsonPath('data.runtime_status', 'inactive');
    expect($this->process->refresh()->desired_state->value)->toBe('stopped');
    $this->postJson('/api/v1/processes/'.$this->process->id.'/start')->assertOk()
        ->assertJsonPath('data.id', $this->process->id)->assertJsonPath('data.desired_state', 'running')->assertJsonPath('data.runtime_status', 'active');

    expect($this->process->refresh()->desired_state->value)->toBe('running');
    $this->assertDatabaseCount('operations', 0);
});

it('creates reuses stops and destroys a Services deploy helper without admitting an Operation', function (): void {
    config(['dependency_detection.services_node_id' => $this->node->id]);
    $this->withHeader('Idempotency-Key', 'services-helper');
    $this->runtime->shouldReceive('converge')->twice();
    $this->runtime->shouldReceive('status')->andReturn('active');
    $this->runtime->shouldReceive('stop')->once();
    $this->runtime->shouldReceive('remove')->once();
    $payload = ['target_type' => 'node', 'target_id' => $this->node->id, 'name' => 'deploy-helper',
        'runtime' => 'systemd', 'command' => ['/bin/sleep', '300'], 'start' => true];

    $id = $this->postJson('/api/v1/processes', $payload)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/processes', $payload)->assertOk()->assertJsonPath('data.id', $id);
    $this->postJson('/api/v1/processes/'.$id.'/stop')->assertOk()->assertJsonPath('data.desired_state', 'stopped');
    $this->deleteJson('/api/v1/processes/'.$id)->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.runtime_status', 'absent');

    $this->assertDatabaseMissing('processes', ['id' => $id]);
    $this->assertDatabaseCount('operations', 0);
});

it('fences an already queued stop if its Node becomes an executor before dispatch', function (): void {
    app()->instance(AgentCommandRunner::class, Mockery::mock(AgentCommandRunner::class));
    $operation = app(ProcessOperationAdmission::class)->execute($this->process, 'stop');
    config(['dependency_detection.services_node_id' => $this->node->id]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'process.stop', 'worker' => 'safety-worker'])->assertOk();

    $this->postJson('/api/v1/operation-executor/'.$operation->id.'/run', [
        'worker' => 'safety-worker', 'key' => 'process-stop', 'transport' => 'agent', 'name' => 'process.control',
        'input' => ['process_id' => $this->process->id, 'runtime' => 'systemd', 'name' => $operation->input['name'], 'on_demand' => false, 'operation' => 'stop'],
    ])->assertConflict()->assertJsonPath('error.code', 'process.executor_dependency');

    expect($this->process->refresh()->desired_state->value)->toBe('running');
    expect($operation->refresh()->state['executor_runs']['process-stop']['started'] ?? false)->toBeFalse();
});

it('also protects Instance-owned executor dependencies at child input admission', function (): void {
    $this->node->roles()->create(['role' => 'app-dev', 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Executor dependency', 'slug' => 'executor-dependency', 'repository_url' => 'git@example.test:executor.git']);
    $instance = Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $this->node->id, 'name' => 'main',
        'environment' => 'development', 'checkout_path' => '/home/orbit/apps/executor',
        'provisioning_step' => 'active', 'status' => 'active',
    ]);
    $this->process->update(['owner_type' => $instance->getMorphClass(), 'owner_id' => $instance->id]);
    config(['dependency_detection.services_node_id' => $this->node->id]);

    expect(fn () => app(ProcessOperationAdmission::class)->inputFor($this->process, 'update'))
        ->toThrow(ResourceOperationException::class, 'Processes on Swamp executor Nodes require synchronous control.');

    $this->assertDatabaseCount('operations', 0);
});

it('still admits Process mutations on a separate workload Node', function (): void {
    $executor = Node::query()->create(['name' => 'separate-executor', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.4', 'public_ssh_host' => '192.0.2.4']);
    config(['dependency_detection.services_node_id' => $executor->id]);

    $this->postJson('/api/v1/processes/'.$this->process->id.'/stop')
        ->assertAccepted()->assertJsonPath('data.kind', 'process.stop');

    $this->assertDatabaseCount('operations', 1);
});
