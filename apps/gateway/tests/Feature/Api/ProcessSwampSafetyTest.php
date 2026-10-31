<?php

declare(strict_types=1);

use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => [
        'kind' => 'started', 'workflowName' => 'operation-process-control', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676',
    ]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'shared.scoped.fixture']);

    $this->node = $this->markAsGateway(Node::query()->create([
        'name' => 'executor-safety', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
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
        'start' => $this->runtime->shouldReceive('start')->once(),
        'restart' => $this->runtime->shouldReceive('restart')->once(),
    };
    $path = '/api/v1/processes/'.$this->process->id;

    $response = match ($verb) {
        'create' => $this->postJson('/api/v1/processes', [
            'target_type' => 'node', 'target_id' => $this->node->id,
            'name' => 'another-dependency', 'runtime' => 'systemd', 'command' => ['/bin/sleep', '300'],
        ]),
        'update' => $this->patchJson($path, ['command' => ['/bin/sleep', '300']]),
        default => $this->postJson($path.'/'.$verb),
    };

    $verb === 'create' ? $response->assertCreated() : $response->assertOk();
    $response->assertJsonPath('data.target_type', 'node')->assertJsonPath('data.target_id', $this->node->id)->assertJsonMissingPath('data.kind');
    if ($verb === 'update') {
        expect($this->process->refresh()->runtime_config['command'])->toBe(['/bin/sleep', '300']);
    } elseif ($verb !== 'create') {
        expect($this->process->refresh()->desired_state->value)->toBe($verb === 'stop' ? 'stopped' : 'running');
    }
    $this->assertDatabaseCount('processes', match ($verb) {
        'create' => 2, default => 1
    });
    $this->assertDatabaseCount('operations', 0);
})->with(['services', 'worker'])->with(['update', 'start', 'restart']);

it('creates and replays one direct intent on an executor Node without Gateway convergence', function (string $peer): void {
    config($peer === 'services'
        ? ['dependency_detection.services_node_id' => $this->node->id]
        : ['operation_executor.worker_node_ids' => [$this->node->id]]);
    $this->withHeader('Idempotency-Key', 'services-helper');
    $this->runtime->shouldNotReceive('converge');
    $payload = ['target_type' => 'node', 'target_id' => $this->node->id, 'name' => 'deploy-helper',
        'runtime' => 'systemd', 'command' => ['/bin/sleep', '300'], 'start' => true];

    $id = $this->postJson('/api/v1/processes', $payload)->assertAccepted()->assertJsonPath('data.kind', 'process.create')->json('data.id');
    $this->postJson('/api/v1/processes', $payload)->assertAccepted()->assertJsonPath('data.id', $id);

    $this->assertDatabaseHas('processes', ['name' => 'deploy-helper', 'status' => 'provisioning']);
    $this->assertDatabaseCount('operations', 1);
})->with(['services', 'worker']);

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
    $executor = Node::query()->create(['name' => 'separate-executor', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.4', 'public_ssh_host' => '192.0.2.4']);
    config(['dependency_detection.services_node_id' => $executor->id]);

    $this->postJson('/api/v1/processes/'.$this->process->id.'/start')
        ->assertAccepted()->assertJsonPath('data.kind', 'process.start');

    $this->assertDatabaseCount('operations', 1);
});
