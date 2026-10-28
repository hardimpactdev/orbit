<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskGroupStatus;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\Schema;

function vm_only_project(array $attributes = []): Project
{
    return Project::query()->create([
        'name' => 'Sandbox', 'slug' => 'sandbox', 'repository_url' => 'https://github.com/acme/sandbox.git',
        'default_branch' => 'main', 'root' => 'public', 'task_check' => 'true', ...$attributes,
    ]);
}

it('leaves no task compute mode in the schema', function (): void {
    expect(Schema::hasColumn('projects', 'task_compute'))->toBeFalse()
        ->and(Schema::hasColumn('tasks', 'task_compute'))->toBeFalse()
        ->and(Schema::hasColumn('task_comments', 'topology_resume'))->toBeFalse();
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

it('lets a Project review and merge on task VMs', function (): void {
    $project = vm_only_project(['review_and_merge' => true, 'merge_check' => 'Required checks']);

    expect($project->reviewsAndMerges())->toBeTrue();
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Work', 'brief' => 'Work', 'status' => TaskGroupStatus::Todo]);
    expect($group->reviewsBeforePush())->toBeTrue();
});
