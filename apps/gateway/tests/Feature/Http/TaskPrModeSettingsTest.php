<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPrMode;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $gateway = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit',
    ]));
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    app(TaskExtensionState::class)->enable();
    $this->project = Project::query()->create(['name' => 'app', 'slug' => 'app', 'repository_url' => 'https://github.com/example/app.git', 'default_branch' => 'main', 'root' => 'public']);
});

describe('the pull request mode of a task group', function (): void {
    it('stores the mode task:create names, and none when it names none', function (): void {
        $named = $this->postJson('/api/v1/tasks', ['project_id' => $this->project->id, 'title' => 'Group', 'brief' => 'Brief', 'pr_mode' => 'pr_when_done'])->assertCreated();
        $plain = $this->postJson('/api/v1/tasks', ['project_id' => $this->project->id, 'title' => 'Other', 'brief' => 'Brief'])->assertCreated();

        expect(Task::topLevel()->findOrFail($named->json('data.id'))->pr_mode)->toBe(TaskPrMode::PrWhenDone)
            ->and(Task::topLevel()->findOrFail($plain->json('data.id'))->pr_mode)->toBeNull();
    });

    it('answers the mode in effect on create, update and show before the group has its copy', function (): void {
        $this->project->update(['task_pr_mode' => TaskPrMode::PrWhenDone]);

        $named = $this->postJson('/api/v1/tasks', ['project_id' => $this->project->id, 'title' => 'Group', 'brief' => 'Brief', 'pr_mode' => 'review_before_pr'])
            ->assertCreated()->assertJsonPath('data.pr_mode', 'review_before_pr')->assertJsonPath('data.pr_mode_source', 'group');
        $plain = $this->postJson('/api/v1/tasks', ['project_id' => $this->project->id, 'title' => 'Other', 'brief' => 'Brief'])
            ->assertCreated()->assertJsonPath('data.pr_mode', 'pr_when_done')->assertJsonPath('data.pr_mode_source', 'project');

        $this->patchJson('/api/v1/tasks/'.$plain->json('data.id'), ['pr_mode' => 'review_before_pr'])->assertOk()
            ->assertJsonPath('data.pr_mode', 'review_before_pr')->assertJsonPath('data.pr_mode_source', 'group');
        $this->project->update(['task_pr_mode' => null]);
        config(['orbit.tasks.pr_mode' => 'pr_when_done']);
        $this->patchJson('/api/v1/tasks/'.$named->json('data.id'), ['title' => 'Renamed'])->assertOk();
        Task::topLevel()->whereKey($named->json('data.id'))->update(['pr_mode' => null]);
        $this->getJson('/api/v1/tasks/'.$named->json('data.id'))->assertOk()
            ->assertJsonPath('data.pr_mode', 'pr_when_done')->assertJsonPath('data.pr_mode_source', 'gateway');
    });

    it('refuses a value that is no mode on create and update', function (): void {
        $this->postJson('/api/v1/tasks', ['project_id' => $this->project->id, 'title' => 'Group', 'brief' => 'Brief', 'pr_mode' => 'merge_when_green'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed')->assertJsonStructure(['error' => ['details' => ['pr_mode']]]);
        $group = Task::topLevel()->create(['project_id' => $this->project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Running]);
        $this->patchJson('/api/v1/tasks/'.$group->id, ['pr_mode' => 'merge_when_green'])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['pr_mode']]]);
    });

    it('changes the mode with task:update while the group has no pull request', function (): void {
        $group = Task::topLevel()->create(['project_id' => $this->project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Running]);

        $this->patchJson('/api/v1/tasks/'.$group->id, ['pr_mode' => 'pr_when_done'])->assertOk();

        expect($group->refresh()->pr_mode)->toBe(TaskPrMode::PrWhenDone);
    });

    it('refuses tasks.pr_mode_locked once the group has a pull request, a publish of it runs, or it ended', function (Closure $lock): void {
        $group = Task::topLevel()->create(['project_id' => $this->project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Running, 'pr_mode' => TaskPrMode::ReviewBeforePr]);
        $lock($group);

        $this->patchJson('/api/v1/tasks/'.$group->id, ['pr_mode' => 'pr_when_done'])->assertConflict()->assertJsonPath('error.code', 'tasks.pr_mode_locked');
        expect($group->refresh()->pr_mode)->toBe(TaskPrMode::ReviewBeforePr);
    })->with([
        'a pull request' => [fn (Task $group) => $group->update(['pr_url' => 'https://github.com/example/app/pull/7'])],
        'a running publish' => [fn (Task $group) => Operation::query()->create(['kind' => OperationKinds::TasksPublish, 'target_type' => OperationKinds::TaskGroupTarget,
            'target_id' => $group->id, 'node_id' => null, 'input' => ['check' => 1], 'status' => OperationStatus::Running, 'sequence' => 1, 'attempts' => 1])],
        'an ended group' => [fn (Task $group) => Task::topLevel()->whereKey($group->id)->update(['status' => TaskGroupStatus::Completed->value])],
    ]);

    it('lets the current mode repeat after a pull request exists', function (): void {
        $group = Task::topLevel()->create(['project_id' => $this->project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Running,
            'pr_mode' => TaskPrMode::PrWhenDone, 'pr_url' => 'https://github.com/example/app/pull/7']);

        $this->patchJson('/api/v1/tasks/'.$group->id, ['pr_mode' => 'pr_when_done'])->assertOk();
    });

    it('refuses the mode on a subtask', function (): void {
        $group = Task::topLevel()->create(['project_id' => $this->project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Backlog]);
        $child = $group->tasks()->create(['position' => 1, 'title' => 'Child', 'brief' => 'Brief']);

        $this->patchJson('/api/v1/tasks/'.$child->id, ['pr_mode' => 'pr_when_done'])->assertUnprocessable();
    });
});

describe('the pull request mode of a Project', function (): void {
    it('sets, shows and clears task_pr_mode with project:update', function (): void {
        $this->patchJson('/api/v1/projects/'.$this->project->id, ['task_pr_mode' => 'pr_when_done'])->assertOk()->assertJsonPath('data.task_pr_mode', 'pr_when_done');
        expect($this->project->refresh()->task_pr_mode)->toBe(TaskPrMode::PrWhenDone);
        $this->getJson('/api/v1/projects/'.$this->project->id)->assertOk()->assertJsonPath('data.task_pr_mode', 'pr_when_done');

        $this->patchJson('/api/v1/projects/'.$this->project->id, ['task_pr_mode' => null])->assertOk()->assertJsonPath('data.task_pr_mode', null);
        expect($this->project->refresh()->task_pr_mode)->toBeNull();
    });

    it('refuses a value that is no mode', function (): void {
        $this->patchJson('/api/v1/projects/'.$this->project->id, ['task_pr_mode' => 'merge_when_green'])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['task_pr_mode']]]);
    });
});
