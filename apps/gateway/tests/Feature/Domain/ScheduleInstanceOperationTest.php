<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperationPrivateMaterial;
use App\Domain\Schedules\ScheduleInstanceOperation;
use App\Domain\Schedules\ScheduleRuntimeAccountResolver;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Schedule;
use App\Models\ScheduleDefinition;
use Tests\Support\Schedules\FakeScheduleRuntimeAccountResolver;

beforeEach(function (): void {
    app()->instance(ScheduleRuntimeAccountResolver::class, new FakeScheduleRuntimeAccountResolver);
    $this->node = Node::query()->create(['name' => 'schedule-plan', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.19', 'wireguard_ip' => '10.44.0.19']);
    $this->node->roles()->create(['role' => 'app-dev', 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Schedule runtime', 'slug' => 'schedule-runtime', 'repository_url' => 'https://example.test/runtime.git']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'name' => 'proof',
        'environment' => 'development', 'checkout_path' => '/srv/proof', 'provisioning_step' => 'active', 'status' => 'active']);
    $this->runtime = app(ScheduleInstanceOperation::class);
});

it('captures only uninstalled owned definition copies and keeps timers disabled', function (): void {
    $copy = scheduleInstanceCopy($this->instance, 'copy');
    scheduleInstanceCopy($this->instance, 'installed', ['status' => 'active']);
    scheduleInstanceCopy($this->instance, 'removing', ['status' => 'removing']);
    scheduleInstanceCopy($this->instance, 'manual', ['source_definition_id' => null]);
    $input = $this->runtime->capture($this->instance);
    expect(array_column($input['members'], 'id'))->toBe([$copy->id])
        ->and($input['members'][0]['input']['desired'])->toBe('disabled')
        ->and($input['members'][0]['input'])->not->toHaveKey('command')
        ->and($copy->refresh()->desired_timer_state->value)->toBe('disabled')
        ->and(Operation::query()->count())->toBe(0);
});

it('hydrates only captured command material and refuses its changed bytes', function (): void {
    $copy = scheduleInstanceCopy($this->instance, 'copy');
    $operation = scheduleInstanceParent($this->instance, $this->runtime->capture($this->instance));
    $private = app(DirectOperationPrivateMaterial::class)->read($operation);
    expect(json_encode($this->runtime->input($operation)))->not->toContain('schedule-private-fixture')
        ->and($private['installations'][$copy->id]['command'])->toBe('/usr/bin/printf schedule-private-fixture');
    $copy->update(['command' => '/usr/bin/true']);
    expect(fn () => app(DirectOperationPrivateMaterial::class)->read($operation))->toThrow(fn (ResourceOperationException $exception): bool => $exception->errorCode === 'operation.execution_forbidden');
});

it('refuses a captured member moved to another owner', function (): void {
    $copy = scheduleInstanceCopy($this->instance, 'copy');
    $operation = scheduleInstanceParent($this->instance, $this->runtime->capture($this->instance));
    $copy->update(['target_type' => Node::class, 'target_id' => $this->node->id]);
    expect(fn () => app(DirectOperationPrivateMaterial::class)->read($operation))->toThrow(fn (ResourceOperationException $exception): bool => $exception->errorCode === 'operation.execution_forbidden');
});

it('projects member installation without enabling its timer or creating child Operations', function (): void {
    $copy = scheduleInstanceCopy($this->instance, 'copy');
    $operation = scheduleInstanceParent($this->instance, $this->runtime->capture($this->instance));
    $result = ['ready' => true, 'members' => [['id' => $copy->id, 'ready' => true]]];
    $this->runtime->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'status' => 'succeeded', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => [...$result, 'private_material' => 'private-extra', 'members' => [[...$result['members'][0], 'stdout' => 'private-extra']]]]);
    expect($operation->refresh()->result)->toBe($result)
        ->and($copy->refresh()->status->value)->toBe('active')
        ->and($copy->desired_timer_state->value)->toBe('disabled')
        ->and(Operation::query()->count())->toBe(1);
});

it('projects a native member failure while retaining its definition record', function (): void {
    $copy = scheduleInstanceCopy($this->instance, 'copy');
    $operation = scheduleInstanceParent($this->instance, $this->runtime->capture($this->instance));
    $result = ['error_code' => 'schedule.artifact_conflict', 'members' => [['id' => $copy->id, 'error_code' => 'schedule.artifact_conflict']]];
    $this->runtime->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'status' => 'failed', 'error_code' => 'schedule.artifact_conflict', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => $result]);
    expect($copy->refresh()->status->value)->toBe('failed')
        ->and($copy->error_code)->toBe('schedule.artifact_conflict')
        ->and($copy->desired_timer_state->value)->toBe('disabled');
});

/** @param array<string,mixed> $attributes */
function scheduleInstanceCopy(Instance $instance, string $name, array $attributes = []): Schedule
{
    $definition = ScheduleDefinition::query()->create(['project_id' => $instance->project_id, 'name' => $name, 'environments' => ['production'], 'spec' => ['command' => '/usr/bin/true', 'calendar' => 'daily', 'timeout_seconds' => 60]]);

    return Schedule::query()->create([...['target_type' => Instance::MorphAlias, 'target_id' => $instance->id, 'host_node_id' => $instance->node_id,
        'name' => $name, 'source_definition_id' => $definition->id, 'command' => '/usr/bin/printf schedule-private-fixture', 'calendar' => 'daily',
        'timeout_seconds' => 60, 'desired_timer_state' => 'disabled', 'status' => 'provisioning'], ...$attributes]);
}

/** @param array<string,mixed> $input */
function scheduleInstanceParent(Instance $instance, array $input): Operation
{
    return Operation::query()->create(['kind' => 'schedule.install_instance_definitions', 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $instance->node_id, 'status' => 'running', 'input' => $input,
        'state' => ['swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]);
}
