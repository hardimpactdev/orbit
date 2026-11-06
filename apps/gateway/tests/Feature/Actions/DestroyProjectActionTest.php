<?php

declare(strict_types=1);

use App\Actions\Projects\DestroyProjectAction;
use App\Domain\Tasks\TaskGroupStatus;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

it('removes a Project together with the old workspace lease rows of its groups', function (): void {
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Old group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Cancelled]);
    DB::table('task_workspace_leases')->insert(['instance_id' => 1, 'group_id' => $group->id, 'kind' => 'check', 'owner' => 'check:1', 'status' => 'released']);

    app(DestroyProjectAction::class)->execute($project);

    expect(Project::query()->whereKey($project->id)->exists())->toBeFalse()
        ->and(DB::table('task_workspace_leases')->where('group_id', $group->id)->exists())->toBeFalse();
});
