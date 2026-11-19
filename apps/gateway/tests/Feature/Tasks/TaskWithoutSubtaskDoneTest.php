<?php

declare(strict_types=1);

use App\Actions\Tasks\CreateTaskGroupAction;
use App\Actions\Tasks\RunTaskMergeRoundAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Tasks\QuestionStatus;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupDone;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskInsights;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskType;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\AgentThread;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskQuestion;
use App\Support\ValidatedData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SwampNodeFixture;
use Tests\Support\TaskDoneFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    SwampNodeFixture::ensure();
    Queue::fake([StartAdmittedSwampRun::class]);
    app(TaskExtensionState::class)->enable();
    $gateway = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit']));
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
});

/**
 * A task with no subtask in its task copy, after a passed handoff check: its work row is running, the check C0 is the done candidate,
 * and no reviewer turn ran on it yet. The copy runs T3 and the work has its implementer thread.
 */
function soloDoneWorld(): TaskDoneFixture
{
    $world = TaskDoneFixture::make();
    $world->turn->delete();
    $world->check->update(['pr_title' => null, 'pr_body' => null, 'pr_text_operation_id' => null]);
    $world->subtask->update(['type' => TaskType::Work, 'title' => $world->group->title, 'brief' => $world->group->brief, 'status' => TaskStatus::Running,
        'deliverables' => [['id' => 'export', 'type' => 'file', 'description' => 'The export exists.', 'path' => 'app/Export.php', 'change' => 'created']]]);
    $runtime = InstanceRuntime::query()->where('instance_id', $world->instance->id)->sole();
    $runtime->delete();
    InstanceRuntime::query()->create([...$runtime->only(['node_id', 'power_state', 'runtime_version', 'agent_version', 'instance_id', 'incus_project', 'container_name', 'bridge_address', 'seed_commit']),
        't3_port' => 3800, 'container_server' => 't3']);
    $implementer = AgentThread::query()->create(['task_group_id' => $world->group->id, 'task_id' => $world->subtask->id, 'node_id' => $world->instance->node_id, 'driver' => 't3',
        'runtime_key' => 'instance:'.$world->instance->id, 'external_id' => 'implementer-thread', 'role' => 'implementer', 'model' => 'claude', 'effort' => 'high']);
    $world->subtask->update(['implementer_agent_thread_id' => $implementer->id]);

    return new TaskDoneFixture($world->group->refresh(), $world->subtask->refresh(), $world->check, $world->instance, $world->reviewer, $world->turn);
}

/** @param  array<string, mixed>  $result */
function soloEnd(Operation $operation, OperationStatus $status = OperationStatus::Succeeded, array $result = ['state' => 'done', 'ended' => true]): Operation
{
    $operation->update(['status' => $status, 'result' => $result, 'error_code' => $status === OperationStatus::Failed ? ($result['error_code'] ?? 'tasks.step_failed') : null,
        'lock_key' => null, 'finished_at' => now()]);

    return $operation->refresh();
}

function soloStep(Task $group, Operation $publish, string $step): Operation
{
    return Operation::query()->where('idempotency_key', "merge-round:{$group->id}:{$publish->id}:{$step}")->sole();
}

it('takes a task with no subtask by its own id from a passed handoff check through review, completion, publish, a merge round and task completion', function (): void {
    $world = soloDoneWorld();
    [$group, $work] = [$world->group, $world->subtask];
    $id = $group->id;

    // Questions and comments: the owner asks and resolves on the task's own id; both land on its work, as on a subtask.
    $asked = $this->postJson("/api/v1/tasks/{$id}/comments", ['type' => 'assistance_requested', 'body' => 'Which format?', 'author' => 'owner'])->assertCreated();
    expect($asked->json('data.task_id'))->toBe($work->id)
        ->and(TaskQuestion::query()->sole()->only(['task_id', 'subtask_id', 'question']))->toBe(['task_id' => $id, 'subtask_id' => $work->id, 'question' => 'Which format?']);
    $this->getJson('/api/v1/task-questions')->assertOk()->assertJsonPath('data.0.task_id', $id);
    $this->postJson("/api/v1/tasks/{$id}/comments", ['type' => 'resolution', 'body' => 'CSV.', 'author' => 'owner'])->assertCreated();
    expect($work->refresh()->assistance_requested)->toBeFalse()->and($group->refresh()->assistance_requested)->toBeFalse();
    $this->getJson("/api/v1/tasks/{$id}/comments")->assertOk()->assertJsonCount(2, 'data')->assertJsonFragment(['body' => 'CSV.'])->assertJsonFragment(['body' => 'Which format?']);

    // The reviewer turn on C0, by the task's id: one brief, the task's deliverables, no subtask part.
    $review = $this->withHeader('Idempotency-Key', 'solo-review')->postJson("/api/v1/tasks/{$id}/turn", ['role' => 'reviewer', 'prompt' => 'Review it.', 'check' => $world->check->id])
        ->assertStatus(202)->assertJsonPath('data.kind', 'tasks.copy_turn')->json('data.id');
    $review = Operation::query()->findOrFail($review);
    $prompt = $review->input['turn']['prompt'] ?? $review->input['prompt'];
    expect($review->input['subtask'])->toBe($work->id)->and($review->input['candidate'])->toBe(TaskDoneFixture::Commit)
        ->and($prompt)->toStartWith("You review an Orbit task in its task copy.\nThis is the first review of this task: check {$world->check->id}")
        ->toContain("<task_brief title=\"Add the export\">\nExport the board as CSV.\n</task_brief>\n\n<deliverables>\n- export")
        ->not->toContain('<subtask_brief')->not->toContain('this subtask');
    $world->check->update(['pr_title' => TaskDoneFixture::Title, 'pr_body' => TaskDoneFixture::Body, 'pr_text_operation_id' => $review->id]);
    soloEnd($review);
    expect(app(TaskGroupDone::class)->check($group->refresh()))->toBeNull();

    // The completion by the task's id is the approval: the work completes, the task stays open, and its work is done on C0.
    $this->patchJson("/api/v1/tasks/{$id}", ['work_status' => 'completed'])->assertOk()
        ->assertJsonPath('data.status', 'running')->assertJsonPath('data.work.status', 'completed')->assertJsonPath('data.subtasks', []);
    expect(app(TaskGroupDone::class)->check($group->refresh())?->id)->toBe($world->check->id);

    // The release publishes C0, and the publish conflicts with main.
    $publish = $this->withHeader('Idempotency-Key', 'solo-publish')->postJson("/api/v1/tasks/{$id}/publish", ['check' => $world->check->id])->assertStatus(202)
        ->assertJsonPath('data.kind', 'tasks.publish')->json('data.id');
    $publish = soloEnd(Operation::query()->findOrFail($publish), OperationStatus::Failed, ['error_code' => RunTaskMergeRoundAction::ConflictCode, 'conflicting_paths' => ['app/Export.php']]);
    expect($publish->input['check'])->toBe($world->check->id);

    // The merge round repairs it on the work: sync, a reopen and implementer turn, a handoff check, a reviewer turn, the completion and a publish.
    $round = app(RunTaskMergeRoundAction::class);
    $round->execute();
    soloEnd(soloStep($group, $publish, 'sync'), result: ['default_branch' => 'main', 'default_commit' => str_repeat('c', 40), 'branch' => 'task-'.$id,
        'branch_commit' => TaskDoneFixture::Commit, 'behind_by' => 1, 'start' => str_repeat('a', 40)]);
    $round->execute();
    $turn = soloStep($group, $publish, 'turn');
    expect($work->refresh()->status)->toBe(TaskStatus::Running)
        ->and($turn->input['subtask'])->toBe($work->id)->and($turn->input['role'])->toBe('implementer');
    soloEnd($turn);
    $round->execute();
    $check = soloStep($group, $publish, 'check');
    expect($check->input['subtask'])->toBe($work->id);
    $c1 = TaskCheck::query()->findOrFail(ValidatedData::integer($check->input['check_id']));
    $merged = str_repeat('d', 40);
    $c1->update(['status' => TaskCheckStatus::Passed, 'pid' => 1, 'process_started' => 'x', 'head_before' => TaskDoneFixture::Commit, 'tree_before' => str_repeat('4', 40),
        'head_after' => $merged, 'tree_after' => str_repeat('5', 40), 'commit_sha' => $merged, 'exit_code' => 0, 'finished_at' => now(), 'pr_mode' => $group->pr_mode]);
    soloEnd($check, result: ['outcome' => 'passed', 'check_id' => $c1->id, 'commit' => $merged, 'head' => $merged, 'tree' => str_repeat('5', 40), 'ci' => null]);
    $round->execute();
    $reviewed = soloStep($group, $publish, 'review');
    expect($reviewed->input['subtask'])->toBe($work->id)->and($reviewed->input['check'])->toBe($c1->id);
    $c1->update(['pr_title' => TaskDoneFixture::Title, 'pr_body' => TaskDoneFixture::Body, 'pr_text_operation_id' => $reviewed->id]);
    soloEnd($reviewed);
    $round->execute();
    $republish = soloStep($group, $publish, 'publish');
    expect($republish->kind)->toBe(OperationKinds::TasksPublish)->and($republish->input['check'])->toBe($c1->id)
        ->and($work->refresh()->status)->toBe(TaskStatus::Completed);
    soloEnd($republish, result: ['branch' => 'task-'.$id, 'commit' => $merged, 'pull_request_number' => 7, 'pull_request_url' => 'https://github.com/example/trial/pull/7']);

    // The pull request merged and the copy is gone: the task completes, and Insights count its work once.
    $group->update(['status' => TaskGroupStatus::Settling, 'pr_url' => 'https://github.com/example/trial/pull/7', 'watched_pr_completion' => 'merged',
        'merged_at' => now(), 'taskable_type' => null, 'taskable_id' => null]);
    $this->postJson("/api/v1/tasks/{$id}/complete")->assertOk()->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.work.status', 'completed')->assertJsonPath('data.subtasks', []);

    $insights = app(TaskInsights::class)->report(30, null, CarbonImmutable::now())->current;
    expect($insights->tasks)->toBe(1)->and($insights->handoffFirstTry)->toBe(1.0);
});

/** A task in backlog with no subtask whose work holds DELIVERABLES; with no work when DELIVERABLES is null. */
function soloDraft(TaskDoneFixture $world, ?array $deliverables = []): Task
{
    $draft = Task::query()->create(['project_id' => $world->group->project_id, 'title' => 'Plan', 'brief' => 'Plan it.', 'status' => TaskGroupStatus::Backlog]);
    if ($deliverables !== null) {
        CreateTaskGroupAction::openWork($draft, $deliverables);
    }

    return $draft->refresh();
}

it('moves the work of a task with no subtask by work_status on the task\'s id, and leaves status to the task', function (): void {
    $world = soloDoneWorld();
    $id = $world->group->id;

    // status stays the task's own: completed is no move of a task, and repeating the status of a failed task is a no-op.
    $this->patchJson("/api/v1/tasks/{$id}", ['status' => 'completed'])->assertUnprocessable()->assertJsonPath('error.code', 'tasks.status_move_refused');
    $this->patchJson("/api/v1/tasks/{$id}", ['work_status' => 'completed'])->assertOk()->assertJsonPath('data.status', 'running')->assertJsonPath('data.work.status', 'completed');
    $this->patchJson("/api/v1/tasks/{$id}", ['status' => 'reviewing'])->assertOk()->assertJsonPath('data.status', 'reviewing')->assertJsonPath('data.work.status', 'completed');
    $this->patchJson("/api/v1/tasks/{$id}", ['status' => 'running'])->assertOk()->assertJsonPath('data.status', 'running')->assertJsonPath('data.work.status', 'completed');

    // The reopen after a completion, as for the last subtask: a fix turn runs on the work again.
    $this->patchJson("/api/v1/tasks/{$id}", ['work_status' => 'running'])->assertOk()->assertJsonPath('data.work.status', 'running');
    $this->withHeader('Idempotency-Key', 'solo-fix')->postJson("/api/v1/tasks/{$id}/turn", ['role' => 'implementer', 'prompt' => 'Fix the merge.'])->assertStatus(202);
    soloEnd(Operation::query()->where('kind', OperationKinds::TasksCopyTurn)->sole());

    // Each change of the work is a request of its own, and takes no field of the task.
    foreach ([['work_status' => 'completed', 'title' => 'Other'], ['work_status' => 'completed', 'status' => 'running'],
        ['work_status' => 'reviewing', 'deliverables' => [], 'reason' => 'Both.']] as $body) {
        $this->patchJson("/api/v1/tasks/{$id}", $body)->assertUnprocessable();
    }
    expect($world->subtask->refresh()->status)->toBe(TaskStatus::Running);

    $this->patchJson("/api/v1/tasks/{$id}", ['work_status' => 'failed'])->assertOk()->assertJsonPath('data.work.status', 'failed');
    $world->group->update(['status' => TaskGroupStatus::Failed]);
    $this->patchJson("/api/v1/tasks/{$id}", ['status' => 'failed'])->assertOk()->assertJsonPath('data.status', 'failed');

    // A task with subtasks, or with no work, takes no work_status.
    $other = TaskDoneFixture::make();
    $this->patchJson("/api/v1/tasks/{$other->group->id}", ['work_status' => 'running'])->assertUnprocessable();
    $this->patchJson('/api/v1/tasks/'.soloDraft($world, null)->id, ['work_status' => 'running'])->assertUnprocessable();
    // A draft's deliverables take no status with them.
    $draft = soloDraft($world);
    $this->patchJson("/api/v1/tasks/{$draft->id}", ['deliverables' => [], 'status' => 'todo'])->assertUnprocessable();
    expect($draft->refresh()->status)->toBe(TaskGroupStatus::Backlog);
});

it('ends the open work of a settling or waiting task with no subtask when the task completes', function (string $status): void {
    $world = soloDoneWorld();
    $world->group->update(['status' => $status, 'taskable_type' => null, 'taskable_id' => null]);

    $this->postJson("/api/v1/tasks/{$world->group->id}/complete")->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.work.status', 'cancelled');
})->with(['settling', 'waiting_for_review']);

it('takes comments on the task\'s id where the request they answer is recorded, and refuses them once the task ended', function (): void {
    $world = soloDoneWorld();
    $id = $world->group->id;
    $thread = $world->subtask->implementer_agent_thread_id;
    $ask = fn (int $task, string $type, string $body, array $more = []) => $this->postJson("/api/v1/tasks/{$task}/comments", ['type' => $type, 'body' => $body, 'author' => 'owner', ...$more]);

    // An attributed comment validates as the work takes it.
    $ask($id, 'assistance_requested', 'Which format?', ['agent_thread_id' => $thread])->assertCreated()->assertJsonPath('data.task_id', $world->subtask->id);
    // A resolution clears the request after the work failed.
    $this->patchJson("/api/v1/tasks/{$id}", ['work_status' => 'failed'])->assertOk();
    $ask($id, 'resolution', 'CSV.', ['agent_thread_id' => $thread])->assertCreated()->assertJsonPath('data.task_id', $world->subtask->id);
    expect($world->subtask->refresh()->assistance_requested)->toBeFalse()->and($world->group->refresh()->assistance_requested)->toBeFalse();
    // A resolution clears assistance; the durable question stays escalated until task:question:close answers it.
    $question = TaskQuestion::query()->sole();
    expect($question->status)->toBe(QuestionStatus::Escalated);
    $this->postJson("/api/v1/task-questions/{$question->id}/close", ['status' => 'answered', 'reason' => 'CSV.'])->assertOk();
    expect($question->refresh()->status)->toBe(QuestionStatus::Answered);

    // A request on a draft with no work clears after the draft takes its own deliverables.
    $draft = soloDraft($world, null);
    $ask($draft->id, 'assistance_requested', 'Which scope?')->assertCreated()->assertJsonPath('data.task_id', $draft->id);
    CreateTaskGroupAction::openWork($draft, []);
    $ask($draft->id, 'resolution', 'All of it.')->assertCreated()->assertJsonPath('data.task_id', $draft->id);
    expect($draft->refresh()->assistance_requested)->toBeFalse();

    // An ended task takes no comment, whatever its work's status.
    $ended = soloDraft($world);
    $ended->update(['status' => TaskGroupStatus::Failed]);
    $ask($ended->id, 'assistance_requested', 'Still there?')->assertConflict()->assertJsonPath('error.code', 'tasks.group_closed');
});

it('resolves the task\'s own request first when the work holds an earlier, independent one, and each in turn', function (): void {
    $world = soloDoneWorld();
    [$id, $work, $group] = [$world->group->id, $world->subtask, $world->group];
    $post = fn (string $type, string $body) => $this->postJson("/api/v1/tasks/{$id}/comments", ['type' => $type, 'body' => $body, 'author' => 'owner'])->assertCreated();

    $post('assistance_requested', 'Which delimiter?');
    $this->patchJson("/api/v1/tasks/{$id}", ['work_status' => 'failed'])->assertOk();
    $post('assistance_requested', 'Who owns follow-up?')->assertJsonPath('data.task_id', $id);
    expect([$work->refresh()->assistance_question, $group->refresh()->assistance_question])->toBe(['Which delimiter?', 'Who owns follow-up?']);

    $post('resolution', 'Alice owns follow-up.')->assertJsonPath('data.task_id', $id);
    expect($group->refresh()->assistance_requested)->toBeFalse()->and($work->refresh()->assistance_requested)->toBeTrue();
    $post('resolution', 'A comma.')->assertJsonPath('data.task_id', $work->id);
    expect($work->refresh()->assistance_requested)->toBeFalse()->and($group->refresh()->assistance_requested)->toBeFalse();
});

it('keeps the history of a draft\'s work: a draft whose work has comments or questions takes no subtask', function (): void {
    $world = soloDoneWorld();
    $draft = soloDraft($world);
    $this->postJson("/api/v1/tasks/{$draft->id}/comments", ['type' => 'assistance_requested', 'body' => 'Split it?', 'author' => 'owner'])->assertCreated();

    $this->postJson('/api/v1/tasks', ['parent_id' => $draft->id, 'title' => 'First', 'brief' => 'First part.'])
        ->assertConflict()->assertJsonPath('error.code', 'tasks.own_work');

    $this->getJson("/api/v1/tasks/{$draft->id}/comments")->assertOk()->assertJsonCount(1, 'data');
    expect(TaskQuestion::query()->where('task_id', $draft->id)->count())->toBe(1)
        ->and(Task::query()->where('parent_id', $draft->id)->sole()->type)->toBe(TaskType::Work);
});
