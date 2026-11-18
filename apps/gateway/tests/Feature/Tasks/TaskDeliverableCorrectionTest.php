<?php

declare(strict_types=1);

use App\Actions\Tasks\UpdateTaskAction;
use App\Data\Tasks\StartTaskCheckData;
use App\Data\Tasks\UpdateTaskData;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskCheckIntent;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskCommentType;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupGuard;
use App\Domain\Tasks\TaskStatus;
use App\Http\Mcp\ApiDispatcher;
use App\Http\Mcp\ApiOperationTool;
use App\Http\Mcp\ToolManifest;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\Support\SwampNodeFixture;
use Tests\Support\TaskDoneFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    app(TaskExtensionState::class)->enable();
});

/** @return list<array<string, string>> */
function wrongDeliverables(): array
{
    return [['id' => 'tools-tests', 'type' => 'command', 'description' => 'The tools tests pass', 'command' => 'python3 -m unittest tools/test_flows.py']];
}

/** @return list<array<string, string>> */
function rightDeliverables(): array
{
    return [['id' => 'tools-tests', 'type' => 'command', 'description' => 'The tools tests pass', 'command' => 'python3 -m unittest discover -s tools']];
}

/**
 * A task copy whose one subtask started and whose newest handoff check failed: the state a wrong deliverable leaves behind.
 *
 * @return array{TaskDoneFixture, TaskCheck}
 */
function failedCopyCheck(): array
{
    $world = TaskDoneFixture::make();
    $world->subtask->update(['status' => TaskStatus::Running, 'deliverables' => wrongDeliverables()]);
    $failed = TaskDoneFixture::check($world->group, $world->subtask, str_repeat('c', 40), status: TaskCheckStatus::Failed);

    return [$world, $failed];
}

function correctDeliverables(Task $group, Task $subtask, ?string $reason = 'The tools tests need discover -s tools.', ?array $deliverables = null): Task
{
    return app(UpdateTaskAction::class)->execute($group, $subtask, new UpdateTaskData(null, null, null, deliverables: $deliverables ?? rightDeliverables(), reason: $reason));
}

function refusedCorrection(Closure $correct): ResourceOperationException
{
    try {
        $correct();
    } catch (ResourceOperationException $exception) {
        return $exception;
    }

    throw new RuntimeException('The correction was accepted.');
}

describe('a correction after a failed check in a task copy', function (): void {
    it('stores the new list and records the old list, the new list, the reason and the failed check in activity', function (): void {
        [$world, $failed] = failedCopyCheck();

        $task = correctDeliverables($world->group, $world->subtask);

        $activity = Activity::query()->where('subject_type', Task::class)->where('subject_id', $world->subtask->id)->where('description', 'deliverables corrected')->sole();
        expect($task->deliverables)->toBe(rightDeliverables())
            ->and($task->status)->toBe(TaskStatus::Running)
            ->and($task->copy_correction_check_id)->toBe($failed->id)
            ->and($activity->properties->all())->toBe(['check_id' => $failed->id, 'reason' => 'The tools tests need discover -s tools.', 'old' => wrongDeliverables(), 'new' => rightDeliverables()]);
    });

    it('lets the next handoff check run the new list', function (): void {
        SwampNodeFixture::ensure();
        [$world] = failedCopyCheck();

        correctDeliverables($world->group, $world->subtask);
        $intent = app(TaskCheckIntent::class)->capture($world->group->refresh(), new StartTaskCheckData($world->subtask->id, TaskCheckKind::Handoff));

        expect($intent['deliverables'])->toBe([[...rightDeliverables()[0], 'fails_on_base' => false]]);
    });

    it('takes one correction per failed check: a second before a new check is locked, and a new failed check takes one again', function (): void {
        [$world] = failedCopyCheck();
        correctDeliverables($world->group, $world->subtask);

        $refused = refusedCorrection(fn (): Task => correctDeliverables($world->group, $world->subtask, deliverables: wrongDeliverables()));
        expect($refused->errorCode)->toBe('tasks.deliverables_locked')
            ->and($world->subtask->refresh()->deliverables)->toBe(rightDeliverables());

        $next = TaskDoneFixture::check($world->group, $world->subtask, str_repeat('d', 40), status: TaskCheckStatus::Failed);
        expect(correctDeliverables($world->group, $world->subtask, deliverables: wrongDeliverables())->copy_correction_check_id)->toBe($next->id);
    });

    it('needs a reason', function (): void {
        [$world] = failedCopyCheck();

        expect(fn (): Task => correctDeliverables($world->group, $world->subtask, reason: null))->toThrow(ValidationException::class)
            ->and($world->subtask->refresh()->deliverables)->toBe(wrongDeliverables())
            ->and(Activity::query()->where('description', 'deliverables corrected')->exists())->toBeFalse();
    });

    it('is locked while the clause it needs fails', function (Closure $break): void {
        [$world] = failedCopyCheck();
        $break($world);

        $refused = refusedCorrection(fn (): Task => correctDeliverables($world->group->refresh(), $world->subtask->refresh()));

        expect($refused->errorCode)->toBe('tasks.deliverables_locked')
            ->and($world->subtask->refresh()->deliverables)->toBe(wrongDeliverables())
            ->and(Activity::query()->where('description', 'deliverables corrected')->exists())->toBeFalse();
    })->with([
        'before any check' => [fn (TaskDoneFixture $world) => TaskCheck::query()->where('task_id', $world->subtask->id)->delete()],
        'after a passed newest check' => [fn (TaskDoneFixture $world) => TaskDoneFixture::check($world->group, $world->subtask, str_repeat('e', 40))],
        'while the newest check runs' => [fn (TaskDoneFixture $world) => TaskDoneFixture::check($world->group, $world->subtask, str_repeat('e', 40), status: TaskCheckStatus::Running)],
        'on a subtask that is not current' => [fn (TaskDoneFixture $world) => Task::query()->create(['parent_id' => $world->group->id, 'position' => 0, 'title' => 'Earlier', 'brief' => 'Earlier.',
            'status' => TaskStatus::Todo, 'deliverables' => rightDeliverables()])],
        'in a group without a task copy' => [fn (TaskDoneFixture $world) => $world->instance->update(['task_workspace_owner_id' => null])],
    ]);

    it('refuses a reason on any other update and changes nothing', function (): void {
        [$world] = failedCopyCheck();
        $todo = Task::query()->create(['parent_id' => $world->group->id, 'position' => 2, 'title' => 'Next', 'brief' => 'Next.', 'status' => TaskStatus::Todo, 'deliverables' => wrongDeliverables()]);

        $planned = refusedCorrection(fn (): Task => correctDeliverables($world->group, $todo));
        $statusOnly = refusedCorrection(fn (): Task => app(UpdateTaskAction::class)->execute($world->group, $world->subtask,
            new UpdateTaskData(null, null, null, status: TaskStatus::Reviewing, reason: 'No reason here.')));

        expect($planned->errorCode)->toBe('tasks.reason_unexpected')->and($planned->status)->toBe(422)
            ->and($statusOnly->errorCode)->toBe('tasks.reason_unexpected')
            ->and($todo->refresh()->deliverables)->toBe(wrongDeliverables())
            ->and($world->subtask->refresh()->status)->toBe(TaskStatus::Running);
    });
});

describe('the reason field of the task update API', function (): void {
    beforeEach(function (): void {
        $gateway = Node::withoutEvents(fn (): Node => Node::query()->create([
            'name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit',
        ]));
        $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    });

    it('carries the reason of a correction into task activity', function (): void {
        [$world, $failed] = failedCopyCheck();

        $this->patchJson("/api/v1/tasks/{$world->subtask->id}", ['deliverables' => rightDeliverables(), 'reason' => 'The tools tests need discover -s tools.'])
            ->assertOk()->assertJsonPath('data.deliverables.0.command', 'python3 -m unittest discover -s tools');

        expect(Activity::query()->where('subject_id', $world->subtask->id)->where('description', 'deliverables corrected')->sole()->properties->all())
            ->toMatchArray(['check_id' => $failed->id, 'reason' => 'The tools tests need discover -s tools.']);
    });

    it('refuses a correction without a reason or with a reason over 500 characters', function (array $reason): void {
        [$world] = failedCopyCheck();

        $this->patchJson("/api/v1/tasks/{$world->subtask->id}", ['deliverables' => rightDeliverables(), ...$reason])
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed')->assertJsonValidationErrors(['reason'], 'error.details');

        expect($world->subtask->refresh()->deliverables)->toBe(wrongDeliverables());
    })->with([
        'no reason' => [[]],
        'an empty reason' => [['reason' => '']],
        'a reason over 500 characters' => [['reason' => str_repeat('r', 501)]],
    ]);

    it('refuses a reason on a group update', function (): void {
        [$world] = failedCopyCheck();

        $this->patchJson("/api/v1/tasks/{$world->group->id}", ['title' => 'Renamed', 'reason' => 'No reason here.'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'tasks.reason_unexpected');

        expect($world->group->refresh()->title)->toBe('Add the export');
    });
});

it('carries the reason through the MCP task-update tool', function (): void {
    $gateway = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit',
    ]));
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    [$world, $failed] = failedCopyCheck();
    $manifest = collect(ToolManifest::default()->definitions())->firstWhere('name', 'task-update');
    $tool = new ApiOperationTool($manifest, app(ApiDispatcher::class));

    $response = $tool->handle(new Laravel\Mcp\Request(['task' => (string) $world->subtask->id, 'deliverables' => rightDeliverables(), 'reason' => 'The tools tests need discover -s tools.']),
        Request::create('/mcp', 'POST', server: ['REMOTE_ADDR' => '10.44.0.2']));

    expect($manifest->inputSchema['properties']['reason'])->toBe(['type' => 'string', 'maxLength' => 500, 'minLength' => 1])
        ->and(json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR)['data']['deliverables'][0]['command'])->toBe('python3 -m unittest discover -s tools')
        ->and(Activity::query()->where('subject_id', $world->subtask->id)->where('description', 'deliverables corrected')->sole()->properties->all())
        ->toMatchArray(['check_id' => $failed->id, 'reason' => 'The tools tests need discover -s tools.']);
});

/** The older correction's state: the subtask asks for assistance after its handoff check failed at `invalid_deliverable`. */
function invalidDeliverableCheck(TaskDoneFixture $world, TaskCheck $failed): void
{
    $receipt = TaskComment::query()->create(['task_group_id' => $world->group->id, 'task_id' => $world->subtask->id, 'completion_attempt' => 1,
        'type' => TaskCommentType::ReadyForReview, 'body' => 'Ready.', 'author' => 'implementer', 'posted_at' => now()]);
    $failed->update(['failed_step' => 'invalid_deliverable', 'task_comment_id' => $receipt->id]);
    $world->subtask->update(['completion_attempt' => 1, 'completion_handoff_comment_id' => $receipt->id]);
    TaskAssistance::apply($world->subtask, AssistanceKind::Failure, reason: 'The handoff check found an invalid deliverable.');
}

describe('the older corrections of a started subtask, decided by the same guard', function (): void {
    it('keeps the one correction after invalid_deliverable without a reason, and the same check then takes no second correction', function (): void {
        [$world, $failed] = failedCopyCheck();
        invalidDeliverableCheck($world, $failed);

        $task = correctDeliverables($world->group, $world->subtask, reason: null);
        $second = refusedCorrection(fn (): Task => correctDeliverables($world->group, $world->subtask, deliverables: wrongDeliverables()));

        expect($task->deliverable_correction_check_id)->toBe($failed->id)
            ->and($task->copy_correction_check_id)->toBeNull()
            ->and(Activity::query()->where('subject_id', $world->subtask->id)->where('description', 'deliverables corrected')->sole()->properties->all())
            ->toBe(['check_id' => $failed->id, 'old' => wrongDeliverables(), 'new' => rightDeliverables()])
            ->and($second->errorCode)->toBe('tasks.deliverables_locked')
            ->and($world->subtask->refresh()->deliverables)->toBe(rightDeliverables());
    });

    it('refuses a reason on the correction after invalid_deliverable', function (): void {
        [$world, $failed] = failedCopyCheck();
        invalidDeliverableCheck($world, $failed);

        expect(refusedCorrection(fn (): Task => correctDeliverables($world->group, $world->subtask))->errorCode)->toBe('tasks.reason_unexpected')
            ->and($world->subtask->refresh()->deliverables)->toBe(wrongDeliverables());
    });

    it('keeps the path gate fix without a reason and records it as a gate fix', function (): void {
        [$world] = failedCopyCheck();
        TaskCheck::query()->where('task_id', $world->subtask->id)->delete();
        TaskAssistance::apply($world->subtask, AssistanceKind::Failure, reason: TaskGroupGuard::DeliverableGatePrefix.'found tools-tests missing.');

        $task = correctDeliverables($world->group, $world->subtask, reason: null);

        expect($task->deliverables)->toBe(rightDeliverables())
            ->and(Activity::query()->where('subject_id', $world->subtask->id)->where('description', 'deliverables corrected')->sole()->properties->all())
            ->toBe(['gate' => true, 'old' => wrongDeliverables(), 'new' => rightDeliverables()]);
    });
});
