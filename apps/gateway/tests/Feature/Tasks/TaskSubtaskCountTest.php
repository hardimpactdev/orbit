<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

// A task leaves backlog with no subtask or with at least two, never with one (docs/reference/tasks.md#a-task-with-no-subtask).

beforeEach(function (): void {
    $gateway = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit',
    ]));
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    app(TaskExtensionState::class)->enable();
    $this->project = Project::query()->create(['name' => 'app', 'slug' => 'app', 'repository_url' => 'https://github.com/example/app.git', 'default_branch' => 'main', 'root' => 'public']);
});

/** @return list<array<string, string>> */
function countDeliverables(): array
{
    return [['id' => 'verdict', 'type' => 'review', 'description' => 'The work is done.']];
}

/** @return list<array<string, mixed>> */
function countSubtasks(int $count): array
{
    return array_map(static fn (int $n): array => ['title' => 'Part '.$n, 'brief' => 'Do part '.$n.'.', 'deliverables' => countDeliverables()], range(1, $count));
}

/** @return array<string, mixed> */
function countShape(string $shape): array
{
    return match ($shape) {
        'no subtask' => ['deliverables' => countDeliverables()],
        'one subtask' => ['subtasks' => countSubtasks(1)],
        'two subtasks' => ['subtasks' => countSubtasks(2)],
    };
}

describe('the move of a task out of backlog', function (): void {
    it('admits a create in todo with no subtask or two, and refuses one subtask and stores nothing', function (string $shape, bool $admitted): void {
        $response = $this->postJson('/api/v1/tasks', ['project_id' => $this->project->id, 'title' => 'Task', 'brief' => 'Brief', 'status' => 'todo', ...countShape($shape)]);

        if ($admitted) {
            $response->assertCreated()->assertJsonPath('data.status', 'todo');

            return;
        }
        $response->assertStatus(422)->assertJsonPath('error.code', 'tasks.one_subtask')
            ->assertJsonPath('error.message', 'A task leaves backlog with no subtask or with at least two. Fold the one subtask into the task: give the task its deliverables and no subtask.');
        expect(Task::topLevel()->count())->toBe(0)->and(Task::query()->count())->toBe(0);
    })->with([
        'no subtask' => ['no subtask', true],
        'one subtask' => ['one subtask', false],
        'two subtasks' => ['two subtasks', true],
    ]);

    it('admits a create in backlog with one subtask, which can still be split or folded there', function (): void {
        $this->postJson('/api/v1/tasks', ['project_id' => $this->project->id, 'title' => 'Task', 'brief' => 'Brief', ...countShape('one subtask')])
            ->assertCreated()->assertJsonPath('data.status', 'backlog');
    });

    it('moves a backlog task to todo with no subtask or two, and refuses one subtask and keeps it in backlog', function (string $shape, bool $admitted): void {
        $id = $this->postJson('/api/v1/tasks', ['project_id' => $this->project->id, 'title' => 'Task', 'brief' => 'Brief', ...countShape($shape)])->assertCreated()->json('data.id');

        $response = $this->patchJson('/api/v1/tasks/'.$id, ['status' => 'todo']);

        if ($admitted) {
            $response->assertOk()->assertJsonPath('data.status', 'todo');

            return;
        }
        $response->assertStatus(422)->assertJsonPath('error.code', 'tasks.one_subtask');
        expect(Task::topLevel()->findOrFail($id)->status)->toBe(TaskGroupStatus::Backlog);
    })->with([
        'no subtask' => ['no subtask', true],
        'one subtask' => ['one subtask', false],
        'two subtasks' => ['two subtasks', true],
    ]);

    it('moves a task to todo after its second subtask is added, and refuses it again once one is destroyed', function (): void {
        $id = $this->postJson('/api/v1/tasks', ['project_id' => $this->project->id, 'title' => 'Task', 'brief' => 'Brief', ...countShape('one subtask')])->assertCreated()->json('data.id');
        $second = $this->postJson('/api/v1/tasks', ['parent_id' => $id, 'title' => 'Part 2', 'brief' => 'Do part 2.', 'deliverables' => countDeliverables()])->assertCreated()->json('data.id');
        $this->deleteJson('/api/v1/tasks/'.$second)->assertSuccessful();

        $this->patchJson('/api/v1/tasks/'.$id, ['status' => 'todo'])->assertStatus(422)->assertJsonPath('error.code', 'tasks.one_subtask');

        $this->postJson('/api/v1/tasks', ['parent_id' => $id, 'title' => 'Part 2', 'brief' => 'Do part 2.', 'deliverables' => countDeliverables()])->assertCreated();
        $this->patchJson('/api/v1/tasks/'.$id, ['status' => 'todo'])->assertOk()->assertJsonPath('data.status', 'todo');
    });

    // A task with one subtask that left backlog before the rule keeps working: the rule reads only the move out of backlog.
    it('lets a started task with one subtask move on', function (): void {
        $group = Task::topLevel()->create(['project_id' => $this->project->id, 'title' => 'Older', 'brief' => 'Brief', 'status' => TaskGroupStatus::Running,
            'orchestration' => 'external', 'implementer_model' => 'claude-opus-5-5', 'reviewer_model' => 'claude-opus-5-5']);
        Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Only', 'brief' => 'Brief', 'deliverables' => countDeliverables(), 'status' => TaskStatus::Running]);

        $this->patchJson('/api/v1/tasks/'.$group->id, ['status' => 'reviewing'])->assertOk()->assertJsonPath('data.status', 'reviewing');
    });
});
