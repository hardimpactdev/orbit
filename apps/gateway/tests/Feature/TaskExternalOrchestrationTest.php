<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\DeliverablePathRepository;
use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupGuard;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\Tasks\TaskPullRequestWatcher;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;

beforeEach(function (): void {
    $gateway = $this->markAsGateway(Node::query()->create([
        'name' => 'external-gateway', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '192.0.2.90', 'wireguard_ip' => '10.44.0.90',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    app(TaskExtensionState::class)->enable();
    $this->project = Project::query()->create([
        'name' => 'External demo', 'slug' => 'external-demo', 'repository_url' => 'https://github.com/acme/demo.git', 'default_branch' => 'main',
    ]);
    $this->mock(InstanceProvisioning::class)->shouldNotReceive('provision');
    $this->mock(DeliverablePathRepository::class)->shouldReceive('defaultBranchCommit')->andReturn(str_repeat('a', 40));
    $this->payload = [
        'project_id' => $this->project->id, 'title' => 'External work', 'brief' => 'An orchestrator drives it.', 'orchestration' => 'external',
        'tasks' => [['title' => 'One', 'brief' => 'Do it.', 'deliverables' => [['id' => 'done', 'type' => 'review', 'description' => 'Done.']]]],
    ];
});

it('creates an immutable external group without claiming it on create or update', function (): void {
    $group = $this->postJson('/api/v1/task-groups', [...$this->payload, 'status' => 'todo'])
        ->assertCreated()->assertJsonPath('data.orchestration', 'external')->assertJsonPath('data.status', 'todo')->json('data');
    $url = '/api/v1/task-groups/'.$group['id'];
    $this->getJson($url)->assertOk()->assertJsonPath('data.orchestration', 'external');
    $this->getJson('/api/v1/task-groups')->assertOk()->assertJsonPath('data.0.orchestration', 'external');
    $this->patchJson($url, ['status' => 'backlog'])->assertOk();
    $this->patchJson($url, ['status' => 'todo'])->assertOk()->assertJsonPath('data.status', 'todo');
    $this->patchJson($url, ['orchestration' => 'tick'])->assertUnprocessable();
    $stored = Task::topLevel()->findOrFail($group['id']);
    expect($stored->groupOrchestration())->toBe(TaskOrchestration::External)->and($stored->tasks()->firstOrFail()->orchestration)->toBeNull();
    expect(fn () => $stored->update(['orchestration' => TaskOrchestration::Tick]))->toThrow(LogicException::class);
});

it('preserves external ownership when a partial group model is read or saved', function (): void {
    $id = $this->postJson('/api/v1/task-groups', $this->payload)->assertCreated()->json('data.id');
    $partial = Task::topLevel()->select(['id', 'title', 'status'])->findOrFail($id);

    expect($partial->groupOrchestration())->toBe(TaskOrchestration::External);
    expect(fn () => TaskGroupGuard::requireTick($partial))->toThrow(ResourceOperationException::class, 'An external orchestrator owns this task group.');
    $partial->update(['title' => 'Renamed']);

    expect($partial->fresh()->groupOrchestration())->toBe(TaskOrchestration::External)
        ->and($partial->fresh()->title)->toBe('Renamed');
});

it('defaults an omitted orchestration to tick and rejects unknown modes', function (): void {
    $payload = $this->payload;
    unset($payload['orchestration']);
    $this->postJson('/api/v1/task-groups', $payload)->assertCreated()->assertJsonPath('data.orchestration', 'tick');
    $this->postJson('/api/v1/task-groups', [...$payload, 'orchestration' => 'other'])->assertUnprocessable();
});

it('refuses an external group with topology before storing it', function (): void {
    $payload = $this->payload;
    $payload['tasks'][0]['topology'] = ['app-dev'];
    $this->postJson('/api/v1/task-groups', $payload)->assertUnprocessable()->assertJsonPath('error.code', 'tasks.orchestration_unsupported');
    expect(Task::topLevel()->count())->toBe(0);
});

it('refuses an external group on a review and merge project', function (): void {
    $this->project->update(['review_and_merge' => true, 'merge_check' => 'Required checks']);
    $this->postJson('/api/v1/task-groups', $this->payload)->assertUnprocessable()->assertJsonPath('error.code', 'tasks.orchestration_unsupported');
    expect(Task::topLevel()->count())->toBe(0);
});

it('refuses topology when adding or changing an external subtask', function (): void {
    $group = $this->postJson('/api/v1/task-groups', $this->payload)->assertCreated()->json('data');
    $url = '/api/v1/task-groups/'.$group['id'].'/tasks';
    $this->postJson($url, ['title' => 'New', 'brief' => 'New.', 'topology' => ['app-dev']])
        ->assertUnprocessable()->assertJsonPath('error.code', 'tasks.orchestration_unsupported');
    $this->patchJson($url.'/'.$group['tasks'][0]['id'], ['topology' => ['app-dev']])
        ->assertUnprocessable()->assertJsonPath('error.code', 'tasks.orchestration_unsupported');
    $this->patchJson($url.'/'.$group['tasks'][0]['id'], ['topology' => []])->assertOk();
    expect(Task::query()->count())->toBe(1);
});

it('records an external resolution and clears assistance without driving work', function (): void {
    $group = $this->postJson('/api/v1/task-groups', $this->payload)->assertCreated()->json('data');
    $url = '/api/v1/task-groups/'.$group['id'].'/tasks/'.$group['tasks'][0]['id'].'/comments';
    $this->postJson($url, ['type' => 'assistance_requested', 'body' => 'Which direction?', 'author' => 'operator'])->assertCreated();
    $this->postJson($url, ['type' => 'resolution', 'body' => 'Continue.', 'author' => 'operator'])->assertCreated();
    expect(Task::topLevel()->findOrFail($group['id'])->assistance_requested)->toBeFalse()
        ->and(Task::query()->findOrFail($group['tasks'][0]['id'])->comments()->count())->toBe(2);
});

it('leaves external work and stale reservations untouched while recording PR state', function (string $state): void {
    $group = Task::topLevel()->create([
        'project_id' => $this->project->id, 'title' => 'Work', 'brief' => 'Work.', 'orchestration' => TaskOrchestration::External,
        'status' => TaskGroupStatus::Running, 'pr_url' => 'https://github.com/acme/demo/pull/42',
    ]);
    $task = Task::query()->create(['parent_id' => $group->id, 'title' => 'One', 'brief' => 'One.', 'status' => TaskStatus::Running]);
    $reserved = Task::topLevel()->create([
        'project_id' => $this->project->id, 'title' => 'Waiting', 'brief' => 'Waiting.', 'orchestration' => TaskOrchestration::External,
        'status' => TaskGroupStatus::Reserved, 'reserved_at' => now()->subDay(),
    ]);
    $watcher = $this->mock(TaskPullRequestWatcher::class);
    $watcher->shouldReceive('status')->once()->withArgs(fn (Task $seen): bool => $seen->id === $group->id)->andReturn($state);
    $watcher->shouldNotReceive('health');
    $scheduler = app(TaskScheduler::class);
    expect($scheduler->tick())->toBe([])->and($scheduler->releaseStaleReservations())->toBe(0)
        ->and($scheduler->claimAvailable())->toBe(0)->and($scheduler->claimNext())->toBeNull();
    $group->refresh();
    expect($group->status)->toBe(TaskGroupStatus::Running)->and($group->watched_pr_state)->toBe($state)
        ->and($group->watched_pr_number)->toBe(42)->and($group->watched_pr_completion)->toBeNull()
        ->and($group->assistance_requested)->toBeFalse()->and($task->fresh()->status)->toBe(TaskStatus::Running)
        ->and($reserved->fresh()->status)->toBe(TaskGroupStatus::Reserved)->and($reserved->fresh()->reserved_at)->not->toBeNull();
})->with(['open', 'merged', 'closed']);

it('does not raise assistance when an external PR observation fails repeatedly', function (): void {
    $group = Task::topLevel()->create([
        'project_id' => $this->project->id, 'title' => 'Work', 'brief' => 'Work.', 'orchestration' => TaskOrchestration::External,
        'status' => TaskGroupStatus::Running, 'pr_url' => 'https://github.com/acme/demo/pull/42',
    ]);
    $this->mock(TaskPullRequestWatcher::class)->shouldReceive('status')->twice()->andThrow(new RuntimeException('Unavailable'));
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    expect($group->fresh()->assistance_requested)->toBeFalse();
});

it('backfills existing groups as tick while leaving subtasks without a mode', function (): void {
    $group = Task::topLevel()->create(['project_id' => $this->project->id, 'title' => 'Existing', 'brief' => 'Existing.']);
    $task = Task::query()->create(['parent_id' => $group->id, 'title' => 'One', 'brief' => 'One.']);
    $migration = require database_path('migrations/2026_12_29_000000_add_orchestration_to_tasks_table.php');
    $migration->down();
    $migration->up();
    expect($group->fresh()->groupOrchestration())->toBe(TaskOrchestration::Tick)->and($task->fresh()->orchestration)->toBeNull();
});

it('keeps group cancel and complete available to the external owner', function (): void {
    $cancel = $this->postJson('/api/v1/task-groups', $this->payload)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/task-groups/'.$cancel.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
    $complete = $this->postJson('/api/v1/task-groups', $this->payload)->assertCreated()->json('data.id');
    Task::topLevel()->findOrFail($complete)->update(['status' => TaskGroupStatus::Settling]);
    $this->postJson('/api/v1/task-groups/'.$complete.'/complete')->assertOk()->assertJsonPath('data.status', 'completed');
});
