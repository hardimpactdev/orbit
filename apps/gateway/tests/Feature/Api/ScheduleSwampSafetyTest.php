<?php

declare(strict_types=1);

use App\Actions\Schedules\CreateScheduleAction;
use App\Data\Schedules\AddScheduleData;
use App\Domain\Schedules\ScheduleOperationAdmission;
use App\Domain\Schedules\ScheduleRuntimeAccountResolver;
use App\Domain\Schedules\ScheduleRuntimeManager;
use App\Domain\Schedules\ScheduleTargetType;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
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

it('keeps unconverted executor Node Schedule mutations synchronous', function (string $peer, string $owner): void {
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
    $enabled = $this->postJson('/api/v1/schedules/'.$id.'/activate');
    if ($owner === 'instance') {
        $enabled->assertOk()->assertJsonPath('data.desired_timer_state', 'enabled');
    } else {
        $enabled->assertUnprocessable()->assertJsonPath('error.code', 'schedule.target_invalid');
    }

    expect($this->runtime->installed)->toBe([$id, $id])
        ->and($this->runtime->activated)->toBe($owner === 'instance' ? [$id] : [])
        ->and($this->runtime->removed)->toBe([]);
    $this->assertDatabaseHas('schedules', ['id' => $id]);
    $this->assertDatabaseCount('operations', 0);
})->with(['services', 'worker'])->with(['node', 'instance']);

it('fences a polled enable when its Node becomes an executor before dispatch', function (): void {
    $schedule = app(CreateScheduleAction::class)->execute(new AddScheduleData(
        ScheduleTargetType::Node, $this->node->id, 'queued-schedule', 'daily', 'true', 60,
    ))['schedule'];
    $operation = app(ScheduleOperationAdmission::class)->execute($schedule, 'enable');
    config(['dependency_detection.services_node_id' => $this->node->id]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'schedule.enable', 'worker' => 'schedule-safety'])->assertOk();

    $this->postJson('/api/v1/operation-executor/'.$operation->id.'/run', [
        'worker' => 'schedule-safety', 'key' => 'schedule-activate', 'transport' => 'agent', 'name' => 'schedule.activate',
        'input' => array_intersect_key($operation->input, array_flip(['id', 'user', 'working_directory'])),
    ])->assertConflict()->assertJsonPath('error.code', 'schedule.executor_dependency');

    expect($operation->refresh()->state['executor_runs']['schedule-activate']['started'] ?? false)->toBeFalse();
    expect(fn () => app(ScheduleOperationAdmission::class)->inputFor($schedule, 'enable'))
        ->toThrow(ResourceOperationException::class, 'Schedules on Swamp executor Nodes require synchronous control.');
});
