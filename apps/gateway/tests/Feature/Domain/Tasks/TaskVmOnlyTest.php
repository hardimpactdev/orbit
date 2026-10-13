<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskSettleMetrics;
use App\Domain\Tasks\TaskSettleMetricsCollector;
use App\Domain\Tasks\TaskStatus;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function vm_only_project(array $attributes = []): Project
{
    return Project::query()->create([
        'name' => 'Sandbox', 'slug' => 'sandbox', 'repository_url' => 'https://github.com/acme/sandbox.git',
        'default_branch' => 'main', 'root' => 'public', 'task_check' => 'true', ...$attributes,
    ]);
}

function drop_task_compute_migration(): object
{
    return require base_path('database/migrations/2026_11_02_000100_drop_task_compute.php');
}

it('leaves no task compute mode in the schema', function (): void {
    expect(Schema::hasColumn('projects', 'task_compute'))->toBeFalse()
        ->and(Schema::hasColumn('tasks', 'task_compute'))->toBeFalse()
        ->and(Schema::hasColumn('task_comments', 'topology_resume'))->toBeFalse();
});

it('restores the columns on rollback as vm and drops them again', function (): void {
    $project = vm_only_project();
    $claimed = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Done', 'brief' => 'Done', 'status' => TaskGroupStatus::Completed]);
    $backlog = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Later', 'brief' => 'Later', 'status' => TaskGroupStatus::Backlog]);
    $migration = drop_task_compute_migration();

    $migration->down();

    expect(DB::table('projects')->where('id', $project->id)->value('task_compute'))->toBe('vm')
        ->and(DB::table('tasks')->where('id', $claimed->id)->value('task_compute'))->toBe('vm')
        ->and(DB::table('tasks')->where('id', $backlog->id)->value('task_compute'))->toBeNull()
        ->and(Schema::hasColumn('task_comments', 'topology_resume'))->toBeTrue();

    $migration->up();

    expect(Schema::hasColumn('projects', 'task_compute'))->toBeFalse()
        ->and(Schema::hasColumn('tasks', 'task_compute'))->toBeFalse()
        ->and(Schema::hasColumn('task_comments', 'topology_resume'))->toBeFalse();
});

it('refuses while a Project or an open group still uses shared compute', function (string $table, string $status): void {
    $project = vm_only_project();
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Work', 'brief' => 'Work', 'status' => $status]);
    $migration = drop_task_compute_migration();
    $migration->down();
    DB::table($table)->where('id', $table === 'projects' ? $project->id : $group->id)->update(['task_compute' => 'shared']);

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Switch every Project and open task group to vm compute');
    expect(Schema::hasColumn('projects', 'task_compute'))->toBeTrue();

    DB::table($table)->update(['task_compute' => 'vm']);
    $migration->up();
})->with([
    'shared Project' => ['projects', 'completed'],
    'running shared group' => ['tasks', 'running'],
]);

it('drops the compute mode of finished shared groups', function (): void {
    $project = vm_only_project();
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Old', 'brief' => 'Old', 'status' => TaskGroupStatus::Completed]);
    $migration = drop_task_compute_migration();
    $migration->down();
    DB::table('tasks')->where('id', $group->id)->update(['task_compute' => 'shared']);

    $migration->up();

    expect(Task::topLevel()->find($group->id)?->status)->toBe(TaskGroupStatus::Completed);
});

it('refuses task_compute in the Project API', function (): void {
    $gateway = $this->markAsGateway(Node::query()->create([
        'name' => 'gateway', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    $project = vm_only_project();

    $this->getJson('/api/v1/projects/'.$project->id)->assertOk()->assertJsonMissingPath('data.task_compute');
    $this->patchJson('/api/v1/projects/'.$project->id, ['task_compute' => 'vm'])->assertUnprocessable();
});

it('waits visibly for a task VM without creating a workspace', function (): void {
    $group = Task::topLevel()->create([
        'project_id' => vm_only_project()->id, 'title' => 'VM task', 'brief' => 'Isolated work.', 'status' => TaskGroupStatus::Todo,
    ]);
    Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'First', 'brief' => 'Work.', 'status' => TaskStatus::Todo]);

    expect(app(TaskScheduler::class)->claimNext())->toBeNull();

    expect($group->fresh()->status)->toBe(TaskGroupStatus::Todo)
        ->and($group->fresh()->capacity_wait_reason)->toBe('Task VM: task VMs are not enabled on this Gateway.')
        ->and(Instance::query()->count())->toBe(0);
});

it('rests a published group in waiting_for_review', function (): void {
    $group = Task::topLevel()->create([
        'project_id' => vm_only_project()->id, 'title' => 'Published task', 'brief' => 'Wait for review.',
        'status' => TaskGroupStatus::Settling, 'pr_url' => 'https://github.com/acme/sandbox/pull/42',
    ]);
    $metrics = \Pest\Laravel\mock(TaskSettleMetricsCollector::class);
    $metrics->shouldReceive('collect')->twice()->andReturn(new TaskSettleMetrics(12, 3, 1000, 0, 0));
    $scheduler = app(TaskScheduler::class);
    $scheduler->settle($group);
    $settledAt = $group->fresh()->settled_at;
    $this->travel(10)->minutes();
    $scheduler->settle($group->fresh(), checkReturningPullRequest: false);

    expect($group->fresh()->status)->toBe(TaskGroupStatus::WaitingForReview)
        ->and($group->fresh()->settled_at->equalTo($settledAt))->toBeTrue()
        ->and($group->fresh()->tokens)->toBe(12);
});

it('keeps an unpublished group in settling', function (): void {
    $group = Task::topLevel()->create([
        'project_id' => vm_only_project()->id, 'title' => 'Unpublished task', 'brief' => 'Publish first.',
        'status' => TaskGroupStatus::Settling,
    ]);
    app(TaskScheduler::class)->settle($group, requestMissingPullRequest: false);

    expect($group->fresh()->status)->toBe(TaskGroupStatus::Settling)->and($group->fresh()->settled_at)->toBeNull();
});

it('lets a Project review and merge on task VMs', function (): void {
    $project = vm_only_project(['review_and_merge' => true, 'merge_check' => 'Required checks']);

    expect($project->reviewsAndMerges())->toBeTrue();
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Work', 'brief' => 'Work', 'status' => TaskGroupStatus::Todo]);
    expect($group->reviewsBeforePush())->toBeTrue();
});
