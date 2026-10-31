<?php

declare(strict_types=1);

use App\Actions\Schedules\CascadeInstanceSchedulesAction;
use App\Actions\Schedules\CompleteScheduleAction;
use App\Actions\Schedules\DestroyScheduleAction;
use App\Domain\Schedules\ScheduleRunStatus;
use App\Domain\Schedules\ScheduleTargetUseGuard;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Schedule;
use Tests\Support\Schedules\FakeScheduleRuntimeManager;

beforeEach(function (): void {
    $this->runtime = new FakeScheduleRuntimeManager;
    $this->node = Node::query()->create(['name' => 'app-dev', 'platform' => 'linux', 'status' => 'active', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.3', 'public_ssh_host' => '192.0.2.20']);
    $this->foreignNode = Node::query()->create(['name' => 'other', 'platform' => 'linux', 'status' => 'active', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.4', 'public_ssh_host' => '192.0.2.21']);
    $project = Project::query()->create(['name' => 'Docs', 'slug' => 'docs', 'repository_url' => 'https://example.test/docs.git']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'name' => 'main', 'environment' => 'development', 'checkout_path' => '/srv/apps/docs', 'status' => 'active', 'provisioning_step' => 'active']);
});

function lifecycle_schedule_record(Node|Instance $owner, Node $host): Schedule
{
    return Schedule::query()->create(['target_type' => $owner instanceof Instance ? Instance::MorphAlias : Node::class, 'target_id' => $owner->id, 'host_node_id' => $host->id, 'name' => 'daily-backup', 'command' => 'true', 'calendar' => 'daily', 'timeout_seconds' => 3600, 'desired_timer_state' => 'disabled', 'status' => 'active']);
}

it('records only latest receipt metadata from the recorded host', function (): void {
    $schedule = lifecycle_schedule_record($this->instance, $this->node);
    $before = $schedule->only(['name', 'calendar', 'command', 'timeout_seconds', 'desired_timer_state', 'status']);
    $complete = new CompleteScheduleAction;

    expect(fn () => $complete->execute($schedule->id, ScheduleRunStatus::Success, $this->foreignNode))
        ->toThrow(ResourceOperationException::class);

    $completed = $complete->execute($schedule->id, ScheduleRunStatus::Error, $this->node);

    expect($completed)->toBeInstanceOf(Schedule::class)
        ->and($completed?->last_run_status)->toBe(ScheduleRunStatus::Error)
        ->and($completed?->last_run_at)->not->toBeNull()
        ->and($completed?->only(array_keys($before)))->toBe($before);
});

it('does not update or recreate state for removing and deleted completion callbacks', function (): void {
    $schedule = lifecycle_schedule_record($this->instance, $this->node);
    $schedule->update(['status' => LifecycleStatus::Removing]);
    $complete = new CompleteScheduleAction;

    $complete->execute($schedule->id, ScheduleRunStatus::Success, $this->node);
    expect($schedule->refresh()->last_run_at)->toBeNull();

    $id = $schedule->id;
    $schedule->delete();
    expect($complete->execute($id, ScheduleRunStatus::Success, $this->node))->toBeNull()
        ->and(Schedule::query()->whereKey($id)->exists())->toBeFalse();
});

it('cascades only the selected Instance schedules and blocks Node removal while hosted', function (): void {
    $owned = lifecycle_schedule_record($this->instance, $this->node);
    $nodeSchedule = lifecycle_schedule_record($this->node, $this->node);

    expect(fn () => new ScheduleTargetUseGuard()->assertNodeRemovable($this->node))
        ->toThrow(fn (ResourceOperationException $exception): bool => $exception->errorCode === 'schedule.target_in_use');

    new CascadeInstanceSchedulesAction(new DestroyScheduleAction($this->runtime))->execute($this->instance->id);

    expect(Schedule::query()->whereKey($owned->id)->exists())->toBeFalse()
        ->and(Schedule::query()->whereKey($nodeSchedule->id)->exists())->toBeTrue()
        ->and($this->runtime->removed)->toContain(['id' => $owned->id, 'cascade' => true]);
});
