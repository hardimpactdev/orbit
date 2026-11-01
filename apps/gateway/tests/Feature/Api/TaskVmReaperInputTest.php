<?php

declare(strict_types=1);

use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\TaskVms\TaskVmReaperInput;
use App\Models\Activity;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskVm;

beforeEach(function (): void {
    $this->travelTo('2026-11-01 12:00:00');
    $project = Project::query()->create(['name' => 'Reaper fixture', 'slug' => 'reaper-fixture', 'repository_url' => 'https://github.com/example/reaper.git']);
    $this->group = Task::topLevel()->forceCreate(['project_id' => $project->id, 'title' => 'Work', 'brief' => 'Work', 'status' => 'running',
        'created_at' => now()->subHours(3), 'started_at' => now()->subHours(2)]);
    $this->vm = TaskVm::query()->create(['provider' => 'incus', 'provider_id' => 'tvm-17', 'name' => 'tvm-17', 'group_id' => $this->group->id,
        'state' => 'ready', 'claimed_at' => now()->subHours(2), 'wireguard_ip' => '10.44.0.129', 'pi_token' => 'private-not-public']);
    $this->capture = static fn (): array => app(TaskVmReaperInput::class)->capture()['vms'][0];
});

it('captures actual Task creation separately from claim time and excludes metric refreshes', function (): void {
    $before = ($this->capture)();
    $this->group->update(['tokens' => 900, 'updated_at' => now()]);
    $this->vm->touch();

    expect(($this->capture)())->toBe($before)
        ->and($before['group_created_at'])->toBe('2026-11-01T09:00:00.000000Z')
        ->and($before['progress_at'])->toBe('2026-11-01T10:00:00.000000Z')
        ->and(json_encode($before))->not->toContain('private-not-public');
});

it('uses subtask and check work timestamps but not a later cancellation finish', function (): void {
    $task = Task::query()->forceCreate(['parent_id' => $this->group->id,
        'title' => 'Step', 'brief' => 'Step', 'status' => 'running', 'created_at' => now()->subHours(2), 'started_at' => now()->subMinutes(50)]);
    TaskCheck::query()->create(['task_id' => $task->id, 'kind' => 'baseline', 'status' => 'passed', 'pid' => 1,
        'process_started' => 'fixture', 'head_before' => 'head', 'tree_before' => 'tree', 'started_at' => now()->subMinutes(30), 'finished_at' => now()->subMinutes(20)]);
    Operation::query()->create(['kind' => 'tasks.turn', 'target_type' => 'task_group', 'target_id' => $this->group->id,
        'status' => 'cancelled', 'sequence' => 1, 'input' => [], 'started_at' => now()->subMinutes(25), 'finished_at' => now()]);

    expect(($this->capture)()['progress_at'])->toBe('2026-11-01T11:40:00.000000Z');
});

it('records turn and check starts and successful finishes as progress', function (): void {
    $operation = Operation::query()->create(['kind' => 'tasks.check', 'target_type' => 'task_group', 'target_id' => $this->group->id,
        'status' => 'running', 'sequence' => 1, 'input' => [], 'started_at' => now()->subMinutes(15)]);
    expect(($this->capture)()['progress_at'])->toBe('2026-11-01T11:45:00.000000Z');

    $operation->update(['status' => 'succeeded', 'finished_at' => now()->subMinutes(5)]);

    expect(($this->capture)()['progress_at'])->toBe('2026-11-01T11:55:00.000000Z');
});

it('keeps the original open assistance age through changes and forgets it after clearing', function (): void {
    TaskAssistance::apply($this->group, AssistanceKind::Direction, 'First question');
    Activity::query()->where('description', 'assistance requested')->update(['created_at' => now()->subHours(3)]);
    TaskAssistance::apply($this->group, AssistanceKind::Direction, 'Changed question', replaceDirection: true);

    expect(($this->capture)()['assistance_requested_at'])->toBe('2026-11-01T09:00:00.000000Z');
    TaskAssistance::apply($this->group);
    expect(($this->capture)()['assistance_requested_at'])->toBeNull();
});

it('distinguishes claimed orphans from unclaimed warm pool VMs and omits destroyed VMs', function (): void {
    $this->vm->update(['group_id' => null]);
    expect(($this->capture)()['group_live'])->toBeFalse()
        ->and(($this->capture)()['group_created_at'])->toBeNull()
        ->and(($this->capture)()['claimed_at'])->not->toBeNull();

    $this->vm->update(['claimed_at' => null]);
    expect(($this->capture)()['claimed_at'])->toBeNull();
    $this->vm->update(['state' => 'destroyed']);
    expect(app(TaskVmReaperInput::class)->capture()['vms'])->toBe([]);
});
