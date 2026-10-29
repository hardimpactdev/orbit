<?php

declare(strict_types=1);

use App\Actions\Processes\StopProcessAction;
use App\Actions\Schedules\CreateScheduleAction;
use App\Data\Schedules\AddScheduleData;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Schedules\ScheduleOperationAdmission;
use App\Domain\Schedules\ScheduleRuntimeAccountResolver;
use App\Domain\Schedules\ScheduleRuntimeManager;
use App\Domain\Schedules\ScheduleTargetType;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use App\Models\Schedule;
use Tests\Support\ProcessesApiFakeRuntimeManager;
use Tests\Support\Schedules\FakeScheduleRuntimeAccountResolver;
use Tests\Support\Schedules\FakeScheduleRuntimeManager;

beforeEach(function (): void {
    $this->node = $this->markAsGateway(Node::query()->create([
        'name' => 'schedule-executor', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit',
        'wireguard_ip' => '10.44.0.3', 'public_ssh_host' => '192.0.2.3',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->runtime = new FakeScheduleRuntimeManager;
    app()->instance(ScheduleRuntimeManager::class, $this->runtime);
    app()->instance(ScheduleRuntimeAccountResolver::class, new FakeScheduleRuntimeAccountResolver);
    app()->instance(AgentCommandRunner::class, Mockery::mock(AgentCommandRunner::class));
});

it('runs executor Node Schedule mutations synchronously with their original responses', function (string $peer, string $owner): void {
    config($peer === 'services'
        ? ['dependency_detection.services_node_id' => $this->node->id]
        : ['operation_executor.worker_node_ids' => [(string) $this->node->id]]);
    $targetId = $this->node->id;
    if ($owner === 'instance') {
        $this->node->roles()->create(['role' => 'app-dev', 'status' => 'active']);
        $project = Project::query()->create(['name' => 'Schedule safety', 'slug' => 'schedule-safety', 'repository_url' => 'git@example.test:safety.git']);
        $instance = Instance::query()->create([
            'project_id' => $project->id, 'node_id' => $this->node->id, 'name' => 'main',
            'environment' => 'development', 'checkout_path' => '/home/orbit/apps/safety',
            'provisioning_step' => 'active', 'status' => 'active',
        ]);
        $targetId = $instance->id;
    }
    $payload = ['target_type' => $owner, 'target_id' => $targetId, 'name' => 'executor-schedule',
        'calendar' => 'daily', 'command' => 'true', ...($owner === 'instance' ? ['start' => false] : [])];
    $this->withHeader('Idempotency-Key', 'executor-schedule');

    $id = $this->postJson('/api/v1/schedules', $payload)->assertCreated()
        ->assertJsonPath('data.status', 'active')->assertJsonMissingPath('data.kind')->json('data.id');
    $this->postJson('/api/v1/schedules', $payload)->assertOk()->assertJsonPath('data.id', $id);
    $this->postJson('/api/v1/schedules/'.$id.'/run')->assertOk()->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.desired_timer_state', $owner === 'instance' ? 'disabled' : 'enabled');
    $enabled = $this->postJson('/api/v1/schedules/'.$id.'/activate');
    if ($owner === 'instance') {
        $enabled->assertOk()->assertJsonPath('data.desired_timer_state', 'enabled');
    } else {
        $enabled->assertUnprocessable()->assertJsonPath('error.code', 'schedule.target_invalid');
    }
    $this->deleteJson('/api/v1/schedules/'.$id)->assertOk()->assertJsonPath('data.id', $id);

    expect($this->runtime->installed)->toBe([$id, $id])
        ->and($this->runtime->ran)->toBe([$id])
        ->and($this->runtime->activated)->toBe($owner === 'instance' ? [$id] : [])
        ->and($this->runtime->removed)->toBe([['id' => $id, 'cascade' => false]]);
    $this->assertDatabaseMissing('schedules', ['id' => $id]);
    $this->assertDatabaseCount('operations', 0);
})->with(['services', 'worker'])->with(['node', 'instance']);

it('can start serve through HTTP after a synchronous Services Schedule stops it', function (): void {
    config(['dependency_detection.services_node_id' => $this->node->id]);
    $serve = Process::query()->create([
        'owner_type' => Node::class, 'owner_id' => $this->node->id, 'name' => 'swamp-serve',
        'runtime' => 'systemd', 'runtime_config' => ['command' => ['/usr/bin/swamp', 'serve']],
        'desired_state' => 'running', 'status' => 'active', 'restart_policy' => 'always',
    ]);
    $processes = new ProcessesApiFakeRuntimeManager;
    app()->instance(ProcessRuntimeManager::class, $processes);
    $id = $this->postJson('/api/v1/schedules', ['target_type' => 'node', 'target_id' => $this->node->id,
        'name' => 'serve-maintenance', 'calendar' => 'daily', 'command' => 'true'])->assertCreated()->json('data.id');
    $runtime = Mockery::mock(ScheduleRuntimeManager::class);
    $runtime->shouldReceive('run')->once()->andReturnUsing(function (Schedule $schedule) use ($id, $serve): void {
        expect($schedule->id)->toBe($id);
        app(StopProcessAction::class)->execute($serve);
    });
    app()->instance(ScheduleRuntimeManager::class, $runtime);

    $this->postJson('/api/v1/schedules/'.$id.'/run')->assertOk()->assertJsonPath('data.id', $id);
    expect($serve->refresh()->desired_state->value)->toBe('stopped');
    $this->postJson('/api/v1/processes/'.$serve->id.'/start')->assertOk()->assertJsonPath('data.desired_state', 'running');

    expect($processes->stopped)->toBe([$serve->id])->and($processes->started)->toBe([$serve->id]);
    $this->assertDatabaseCount('operations', 0);
});

it('fences an admitted run when its Node becomes an executor before dispatch', function (): void {
    $schedule = app(CreateScheduleAction::class)->execute(new AddScheduleData(
        ScheduleTargetType::Node, $this->node->id, 'queued-schedule', 'daily', 'true', 60,
    ))['schedule'];
    $operation = app(ScheduleOperationAdmission::class)->execute($schedule, 'run');
    config(['dependency_detection.services_node_id' => $this->node->id]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'schedule.run', 'worker' => 'schedule-safety'])->assertOk();

    $this->postJson('/api/v1/operation-executor/'.$operation->id.'/run', [
        'worker' => 'schedule-safety', 'key' => 'schedule-run', 'transport' => 'agent', 'name' => 'schedule.run',
        'input' => array_intersect_key($operation->input, array_flip(['id', 'user', 'working_directory'])),
    ])->assertConflict()->assertJsonPath('error.code', 'schedule.executor_dependency');

    expect($operation->refresh()->state['executor_runs']['schedule-run']['started'] ?? false)->toBeFalse();
    expect($this->runtime->ran)->toBe([]);
    expect(fn () => app(ScheduleOperationAdmission::class)->inputFor($schedule, 'run'))
        ->toThrow(ResourceOperationException::class, 'Schedules on Swamp executor Nodes require synchronous control.');
});
