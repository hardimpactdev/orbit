<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskBriefCoverage;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPullRequestDescription;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskSessionClassificationException;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskTurnFetchNotice;
use App\Domain\Tasks\TaskTurnInstructions;
use App\Domain\Tasks\TaskTurnPullRequest;
use App\Domain\Tasks\TaskWorkspaceCommitReceipt;
use App\Domain\Tasks\TaskWorkspaceSigner;
use App\Models\Instance;
use App\Models\Task;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Ai\Classification;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Tests\Support\AgentCommandDispatcher;
use Tests\Support\FakeTaskCheckRunner;
use Tests\Support\FakeTaskTurnReceipts;

beforeEach(function (): void {
    tick_bind_defaults();
});

it('continues an approval tick after Jev recording fails and reaches later work', function (): void {
    [$group, $task, , $signer, $publisher] = tick_review([tick_final_approval()], last: true);
    Classification::fake([['subtask_'.$task->id => new BooleanAnswer(0.97)]])->preventStrayClassifications();
    Exceptions::fake();
    DB::statement("CREATE TRIGGER fail_jev_decision_insert_during_tick BEFORE INSERT ON jev_decisions BEGIN SELECT RAISE(ABORT, 'jev bookkeeping insert failed'); END");

    app(TaskScheduler::class)->tick();

    expect($signer->messages)->toBe(["Models\n\nChecked the feature."])
        ->and($publisher->pushes)->toBe([$group->id])
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::WaitingForReview)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Completed);
    Exceptions::assertReported(QueryException::class);
});

it('publishes when a change starts with the last subtask title, without asking Jev', function (): void {
    [$group, $task, , $signer, $publisher] = tick_review([tick_final_approval(['Models: tasks store their records.'])], last: true);
    Classification::fake([['subtask_'.$task->id => new BooleanAnswer(0.45)]])->preventStrayClassifications();

    app(TaskScheduler::class)->tick();

    Classification::assertNothingClassified();
    expect($signer->messages)->toBe(["Models\n\nChecked the feature."])
        ->and($publisher->pushes)->toBe([$group->id])
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::WaitingForReview)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Completed);
});

it('holds the approval when no change starts with the last subtask title and Jev answers no', function (): void {
    [, $task, , $signer, $publisher] = tick_review([tick_final_approval()], last: true);
    Classification::fake([['subtask_'.$task->id => new BooleanAnswer(0.45)]])->preventStrayClassifications();

    app(TaskScheduler::class)->tick();

    expect(app(AgentCommandDispatcher::class)->commands[0]['message']['text'])->toContain('The pull request change list does not cover the subtask "Models".')
        ->and($signer->messages)->toBe([])
        ->and($publisher->pushes)->toBe([])
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing);
});

it('commits the last approved subtask, opens the pull request with the reviewer fields, and settles the group', function (): void {
    [$group, $task, , $signer] = tick_review([tick_final_approval()], last: true);
    $publishing = tick_publishing();

    app(TaskScheduler::class)->tick();

    $approval = $task->comments()->sole();
    expect($signer->messages)->toBe(["Models\n\nChecked the feature."])
        ->and($publishing->publisher->pushes)->toBe([$group->id])
        ->and($publishing->publisher->bodies)->toBe([TaskPullRequestDescription::render(new TaskTurnPullRequest('Adds tick routing.', ['Tasks store their records.'], []), 1, $group->project->taskCheckCommand())])
        ->and($approval->pull_request)->toBe(['summary' => 'Adds tick routing.', 'changes' => ['Tasks store their records.'], 'breaking' => []])
        ->and($publishing->coverage->approvalCommentId)->toBe($approval->id)
        ->and($publishing->coverage->approvalChanges)->toBe(['Tasks store their records.'])
        ->and($group->fresh()?->pr_url)->toBe('https://github.com/acme/orbit/pull/42')
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::WaitingForReview)
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($task->fresh()?->status)->toBe(TaskStatus::Completed);
});

it('counts only the delivered subtasks in the pull request description', function (): void {
    [$group, , , $signer] = tick_review([tick_final_approval()], last: true);
    foreach ([TaskStatus::Completed, TaskStatus::Cancelled, TaskStatus::Failed] as $index => $status) {
        Task::query()->create(['parent_id' => $group->id, 'position' => $index + 2, 'title' => $status->value, 'brief' => 'Other subtask.', 'status' => $status]);
    }
    $publishing = tick_publishing();

    app(TaskScheduler::class)->tick();

    expect($signer->messages)->toHaveCount(1)
        ->and($publishing->publisher->bodies)->toBe([TaskPullRequestDescription::render(new TaskTurnPullRequest('Adds tick routing.', ['Tasks store their records.'], []), 2, $group->project->taskCheckCommand())]);
});

it('reminds the reviewer when the approval of the last subtask has no pull request fields', function (): void {
    [$group, $task, , $signer] = tick_review([FakeTaskTurnReceipts::contents('approved')], last: true);
    $publishing = tick_publishing();

    app(TaskScheduler::class)->tick();

    expect(app(AgentCommandDispatcher::class)->commands[0]['message']['text'])->toBe(TaskTurnFetchNotice::Failed."\n\n".'Orbit could not confirm the review is complete. The approval of the last subtask needs --pr-summary, --pr-change, and --pr-breaking. '.TaskTurnInstructions::reviewer(final: true, threadId: $group->reviewer_agent_thread_id))
        ->and($publishing->coverage->calls)->toBe(0)
        ->and($signer->messages)->toBe([]);
});

it('names each subtask the change list misses and does not commit', function (): void {
    [$group, $task, , $signer] = tick_review([tick_final_approval()], last: true);
    $publishing = tick_publishing([['Models']]);

    app(TaskScheduler::class)->tick();

    expect(app(AgentCommandDispatcher::class)->commands[0]['message']['text'])->toContain('The pull request change list does not cover the subtask "Models".')
        ->and($signer->messages)->toBe([])
        ->and($publishing->publisher->bodies)->toBe([])
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing);
});

it('retries opening the pull request without storing the approval twice', function (): void {
    [$group, $task, , $signer] = tick_review([tick_final_approval()], last: true);
    $publishing = tick_publishing([[], []], failures: 1);

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->communication_failures)->toBe(1)
        ->and($group->fresh()?->pr_url)->toBeNull()
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($task->comments()->sole()->commit_sha)->toBe(str_repeat('c', 40))
        ->and($signer->messages)->toHaveCount(1)
        ->and($publishing->publisher->pushes)->toBe([$group->id]);

    app(TaskScheduler::class)->tick();
    expect($publishing->publisher->pushes)->toBe([$group->id]);

    $this->travel(TaskScheduler::retryDelaySeconds(1))->seconds();
    app(TaskScheduler::class)->tick();

    expect($task->comments()->count())->toBe(1)
        ->and($task->comments()->sole()->commit_sha)->toBe(str_repeat('c', 40))
        ->and($signer->messages)->toHaveCount(1)
        ->and($publishing->publisher->pushes)->toBe([$group->id, $group->id])
        ->and($publishing->publisher->bodies)->toHaveCount(2)
        ->and($group->fresh()?->pr_url)->toBe('https://github.com/acme/orbit/pull/42')
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::WaitingForReview);
});

it('retries publication after Orbit commits and changes HEAD itself', function (): void {
    [$group, $task] = tick_review([tick_final_approval()], last: true);
    $publishing = tick_publishing([[], []], failures: 1);
    $checks = app(TaskCheckRunner::class);
    if (! $checks instanceof FakeTaskCheckRunner) {
        throw new RuntimeException('The publication retry needs the fake check runner.');
    }
    $signer = new class($checks) implements TaskWorkspaceSigner
    {
        public int $commits = 0;

        public function __construct(private FakeTaskCheckRunner $checks) {}

        public function commit(Instance $instance, string $message, bool $allowEmpty = false, ?TaskWorkspaceCommitReceipt $receipt = null): ?string
        {
            $this->commits++;
            $this->checks->head = str_repeat('c', 40);

            return $this->checks->head;
        }
    };
    app()->instance(TaskWorkspaceSigner::class, $signer);

    app(TaskScheduler::class)->tick();

    expect($task->comments()->sole()->commit_sha)->toBe(str_repeat('c', 40))
        ->and($signer->commits)->toBe(1)
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($publishing->publisher->bodies)->toHaveCount(1);

    $this->travel(TaskScheduler::retryDelaySeconds(1))->seconds();
    app(TaskScheduler::class)->tick();

    expect($signer->commits)->toBe(1)
        ->and($publishing->publisher->bodies)->toHaveCount(2)
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::WaitingForReview);
});

it('refuses a reset to the pre-approval HEAD after Orbit committed', function (): void {
    [$group, $task] = tick_review([tick_final_approval()], last: true);
    $publishing = tick_publishing([[], []], failures: 1);
    $checks = app(TaskCheckRunner::class);
    if (! $checks instanceof FakeTaskCheckRunner) {
        throw new RuntimeException('The publication retry needs the fake check runner.');
    }
    $signer = new class($checks) implements TaskWorkspaceSigner
    {
        public int $commits = 0;

        public function __construct(private FakeTaskCheckRunner $checks) {}

        public function commit(Instance $instance, string $message, bool $allowEmpty = false, ?TaskWorkspaceCommitReceipt $receipt = null): ?string
        {
            $this->commits++;
            $this->checks->head = str_repeat('c', 40);

            return $this->checks->head;
        }
    };
    app()->instance(TaskWorkspaceSigner::class, $signer);

    app(TaskScheduler::class)->tick();

    expect($task->comments()->sole()->commit_sha)->toBe(str_repeat('c', 40))
        ->and($publishing->publisher->bodies)->toHaveCount(1);

    $checks->head = str_repeat('a', 40);
    app(TaskScheduler::class)->tick();

    expect($publishing->publisher->bodies)->toHaveCount(1)
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Reviewing)
        ->and($signer->commits)->toBe(1)
        ->and($task->fresh()?->assistance_requested)->toBeFalse();
});

it('counts a failed coverage answer as a communication failure', function (): void {
    [$group, $task, , $signer] = tick_review([tick_final_approval()], last: true);
    tick_publishing();
    app()->instance(TaskBriefCoverage::class, new class implements TaskBriefCoverage
    {
        public function missing(Task $group, TaskTurnPullRequest $pullRequest, ?int $approvalCommentId = null, ?array $approvalChanges = null): array
        {
            throw new TaskSessionClassificationException('TypeSafe Jev request failed (ConnectionException).');
        }
    });

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->communication_failures)->toBe(1)
        ->and($signer->messages)->toBe([])
        ->and(app(AgentCommandDispatcher::class)->commands)->toBe([]);
});
