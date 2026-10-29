<?php

declare(strict_types=1);

use App\Actions\Processes\StartProcessAction;
use App\Actions\Processes\StopProcessAction;
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

it('refuses executor-host mutations without changing intent or touching its runtime', function (string $peer, string $verb): void {
    config($peer === 'services'
        ? ['dependency_detection.services_node_id' => (string) $this->node->id]
        : ['operation_executor.worker_node_ids' => [$this->node->id]]);
    $this->process->update(['name' => $peer === 'worker' ? 'renamed-executor-dependency' : 'swamp-serve']);
    $before = $this->process->refresh()->getAttributes();
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

    $response->assertConflict()->assertJsonPath('error.code', 'process.executor_dependency');
    expect($this->process->refresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('processes', 1);
    $this->assertDatabaseCount('operations', 0);
})->with(['services', 'worker'])->with(['create', 'update', 'start', 'stop', 'restart', 'destroy']);

it('leaves synchronous stop and recovery available without a Swamp worker', function (): void {
    config(['dependency_detection.services_node_id' => $this->node->id]);
    $this->runtime->shouldReceive('stop')->once()->withArgs(fn (Process $process): bool => $process->id === $this->process->id);
    $this->runtime->shouldReceive('start')->once()->withArgs(fn (Process $process, bool $explicit): bool => $process->id === $this->process->id && $explicit);
    $this->runtime->shouldReceive('status')->andReturn('inactive', 'active');

    app(StopProcessAction::class)->execute($this->process);
    expect($this->process->refresh()->desired_state->value)->toBe('stopped');
    app(StartProcessAction::class)->execute($this->process);

    expect($this->process->refresh()->desired_state->value)->toBe('running');
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
