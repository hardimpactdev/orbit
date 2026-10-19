<?php

declare(strict_types=1);

use App\Actions\Tasks\CancelTaskCheckAction;
use App\Actions\Tasks\RetryTaskBaselineAction;
use App\Actions\Tasks\StoreTaskCommentAction;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\QuestionStatus;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskBaseBranchFetcher;
use App\Domain\Tasks\TaskCheckException;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckReading;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskTopologyAdmission;
use App\Domain\Tasks\TaskTurnReceipts;
use App\Domain\Tasks\TaskWorkspaceSigner;
use App\Domain\TaskVms\TaskVmSettings;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\ProjectLifecycleStep;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use App\Models\TaskQuestion;
use App\Models\TaskWorkspaceLeaseRecord;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Classification;
use Symfony\Component\Process\Process;
use Tests\Support\AgentCommandDispatcher;
use Tests\Support\AgentSnapshotReader;
use Tests\Support\FakeTaskCheckRunner;
use Tests\Support\FakeTaskTurnReceipts;

use function Pest\Laravel\mock;

beforeEach(function (): void {
    tick_bind_defaults();
});

it('waits while the check process runs and starts the reviewer only after it passes', function (): void {
    $finished = TaskCheckReading::finished(0, str_repeat('a', 40), str_repeat('b', 40), [], "checks passed\n", 1790170874.25);
    [$group, $task, $checks] = tick_checking([TaskCheckReading::running(), TaskCheckReading::running(), $finished]);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $check = TaskCheck::query()->sole();
    expect($checks->starts)->toBe(1)
        ->and($check->status)->toBe(TaskCheckStatus::Running)
        ->and($check->task_comment_id)->toBe($task->comments()->sole()->id)
        ->and($check->pid)->toBe(4001)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and(app(AgentCommandDispatcher::class)->commands)->toBe([]);

    app(TaskScheduler::class)->tick();

    expect($check->fresh()?->status)->toBe(TaskCheckStatus::Passed)
        ->and($check->fresh()?->exit_code)->toBe(0)
        ->and($check->fresh()?->finished_at?->getTimestamp())->toBe(1790170874)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing);
});

it('requires implementer correction instead of accepting check leftovers as a new baseline', function (): void {
    $changed = TaskCheckReading::finished(0, str_repeat('a', 40), str_repeat('c', 40), ['orbit_tia_fixture/test.php'], "ok\n");
    [$group, $task, $checks] = tick_checking([$changed, FakeTaskCheckRunner::passed()]);

    $signer = Mockery::mock(TaskWorkspaceSigner::class);
    $signer->shouldNotReceive('commit');
    app()->instance(TaskWorkspaceSigner::class, $signer);

    app(TaskScheduler::class)->tick();
    $checks->tree = str_repeat('c', 40);
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($checks->starts)->toBe(1);
    expect(TaskCheck::query()->sole()->status)->toBe(TaskCheckStatus::Changed);
    expect(app(AgentCommandDispatcher::class)->commands[0]['message']['text'])->toContain('The workspace changed while composer check ran. Changed paths: orbit_tia_fixture/test.php.');
    expect($task->fresh()->status)->toBe(TaskStatus::Running);
    expect($task->fresh()->review_workspace_head)->toBeNull();
});

it('holds a tree-changing baseline for assistance without restarting on its leftovers', function (): void {
    $group = tick_baseline_group('changed-baseline', 'composer check', [], '10.51.0.19');
    $checks = new FakeTaskCheckRunner([TaskCheckReading::finished(0, str_repeat('a', 40), str_repeat('c', 40), ['orbit_tia_fixture/test.php'], 'check changed tree')]);
    app()->instance(TaskCheckRunner::class, $checks);
    app(TaskExtensionState::class)->enable();

    app(TaskScheduler::class)->tick();
    $checks->tree = str_repeat('c', 40);
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($checks->starts)->toBe(1);
    expect($group->fresh()->assistance_requested)->toBeTrue();
    expect($group->tasks()->sole()->assistance_reason)->toContain('The workspace changed while the baseline check ran.');
    expect($group->tasks()->sole()->implementer_agent_thread_id)->toBeNull();
    expect(TaskCheck::query()->sole()->status)->toBe(TaskCheckStatus::Changed);
});

it('retains a lost check lease because its child command may still run', function (): void {
    [$group, $task, $checks] = tick_checking([TaskCheckReading::lost('The supervisor disappeared.'), TaskCheckReading::lost('The supervisor disappeared.')]);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($checks->starts)->toBe(1)
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and(TaskCheck::query()->sole()->status)->toBe(TaskCheckStatus::Lost)
        ->and(TaskWorkspaceLeaseRecord::query()->where('status', 'held')->sole()->pid)->toBe(4001)
        ->and(app(AgentCommandDispatcher::class)->commands)->toBe([]);
});

it('reminds the implementer after an operator cancels the check', function (): void {
    [$group, $task, $checks] = tick_checking([TaskCheckReading::finished(143, str_repeat('a', 40), str_repeat('b', 40), [], 'Cancelled.')]);
    app(TaskScheduler::class)->tick();

    $cancelled = app(CancelTaskCheckAction::class)->execute($group, $task);
    app(TaskScheduler::class)->tick();

    expect($cancelled->status)->toBe(TaskCheckStatus::Cancelled)
        ->and($checks->cancels)->toBe(1)
        ->and(app(AgentCommandDispatcher::class)->commands[0]['message']['text'])->toContain("An operator cancelled Orbit's composer check before it finished.")
        ->and($checks->starts)->toBe(1);
});

it('keeps a check running when the workspace cannot be read and counts a communication failure', function (): void {
    [$group, $task] = tick_checking([]);
    app(TaskScheduler::class)->tick();
    mock(TaskCheckRunner::class)->shouldReceive('read')->andThrow(new TaskCheckException('The task workspace could not be reached for the check.'));

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->communication_failures)->toBe(1)
        ->and(TaskCheck::query()->sole()->status)->toBe(TaskCheckStatus::Running)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Running);
});

describe('a thread that works outside the task phase', function (): void {
    it('queues a consult until the shared reviewer releases the workspace', function (): void {
        $group = tick_group();
        $task = $group->tasks->sole();
        app(TaskExtensionState::class)->enable();
        $dispatcher = tick_dispatcher();
        app()->instance(AgentCommandDispatcher::class, $dispatcher);
        app()->instance(AgentSnapshotReader::class, tick_thread_states(['implementer-thread' => 'done', 'reviewer-thread' => 'running']));
        $receipts = new FakeTaskTurnReceipts([FakeTaskTurnReceipts::contents('blocked', 'Composer cannot reach the private package mirror.', 'Should I add the mirror credentials to auth.json?')]);
        $dispatcher = tick_dispatcher();
        $state = (object) ['implementer' => 'done', 'reviewer' => 'running', 'sharedReviewer' => 'running'];
        tick_relay_runtime($receipts, $dispatcher, $state);

        app(TaskScheduler::class)->tick();

        expect($task->comments()->count())->toBe(0)
            ->and($receipts->cleared)->toBe([])
            ->and($task->fresh()?->assistance_requested)->toBeFalse()
            ->and($group->fresh()?->assistance_requested)->toBeFalse()
            ->and(TaskQuestion::query()->exists())->toBeFalse()
            ->and($dispatcher->commands)->toBe([]);

        $state->reviewer = 'done';
        $state->sharedReviewer = 'done';
        app(TaskScheduler::class)->tick();

        expect($task->comments()->sole()->getRawOriginal('type'))->toBe('blocked')
            ->and($receipts->cleared)->toHaveCount(1)
            ->and($task->fresh()?->consult_comment_id)->toBe($task->comments()->sole()->id)
            ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Open)
            ->and(collect($dispatcher->commands)->contains(fn (array $command): bool => ($command['type'] ?? null) === 'create'))->toBeTrue();
    });

    it('leaves a running task alone while its implementer works', function (string $reviewerState): void {
        $group = tick_group();
        $task = $group->tasks->sole();
        app(TaskExtensionState::class)->enable();
        $dispatcher = tick_dispatcher();
        app()->instance(AgentCommandDispatcher::class, $dispatcher);
        app()->instance(AgentSnapshotReader::class, tick_thread_states(['implementer-thread' => 'running', 'reviewer-thread' => $reviewerState]));
        $receipts = new FakeTaskTurnReceipts([FakeTaskTurnReceipts::contents('blocked', 'Stuck.', 'Which API version?')]);
        app()->instance(TaskTurnReceipts::class, $receipts);

        $decisions = app(TaskScheduler::class)->tick();

        expect($decisions)->toBe([])
            ->and($receipts->cleared)->toBe([])
            ->and($task->comments()->count())->toBe(0)
            ->and($task->fresh()?->assistance_requested)->toBeFalse()
            ->and($task->fresh()?->status)->toBe(TaskStatus::Running)
            ->and($dispatcher->commands)->toBe([]);
        Classification::assertNothingClassified();
    })->with(['idle', 'running']);

    it('defers the handoff check and review until the running reviewer releases the workspace', function (): void {
        $group = tick_group();
        $task = $group->tasks->sole();
        AgentThread::query()->whereKey($group->reviewer_agent_thread_id)->update(['task_id' => $task->id]);
        app(TaskExtensionState::class)->enable();
        $spawner = new class implements AgentSpawner
        {
            public int $reviews = 0;

            public function reserveReviewer(Task $task): ?int
            {

                return null;
            }

            public function spawnReviewer(Task $task): ?int
            {
                return null;
            }

            public function spawnImplementer(Task $task): ?int
            {
                return null;
            }

            public function requestReview(Task $task): void
            {
                $this->reviews++;
            }
        };
        app()->instance(AgentSpawner::class, $spawner);
        $reader = tick_thread_states(['implementer-thread' => 'done', 'reviewer-thread' => 'running']);
        app()->instance(AgentSnapshotReader::class, $reader);

        app(TaskScheduler::class)->tick();
        app(TaskScheduler::class)->tick();
        app(TaskScheduler::class)->tick();

        expect($task->fresh()?->status)->toBe(TaskStatus::Running)
            ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running)
            ->and($task->checks()->count())->toBe(0)
            ->and($spawner->reviews)->toBe(0)
            ->and($task->fresh()?->review_notified_attempt)->toBeNull()
            ->and($task->fresh()?->communication_failures)->toBe(0)
            ->and(app(TaskTurnReceipts::class)->prepared)->toBe([]);

        $reader->states['reviewer-thread'] = 'idle';
        app(TaskScheduler::class)->tick();
        app(TaskScheduler::class)->tick();

        expect($spawner->reviews)->toBe(1)
            ->and($task->fresh()?->review_notified_attempt)->toBe($task->fresh()?->review_attempt)
            ->and($task->fresh()?->review_notified_turn_id)->toBe('reviewer-thread-turn')
            ->and(app(TaskTurnReceipts::class)->prepared)->toBe(['reviewer:final']);
    });

    it('relays review findings only once the implementer is idle', function (): void {
        $group = tick_group();
        $task = $group->tasks->sole();
        $group->update(['status' => TaskGroupStatus::Reviewing]);
        $task->update(['status' => TaskStatus::Reviewing, 'review_notified_attempt' => $task->review_attempt, 'review_notified_turn_id' => 'handoff-turn', ...tick_review_baseline()]);
        app(TaskExtensionState::class)->enable();
        $dispatcher = tick_dispatcher();
        app()->instance(AgentCommandDispatcher::class, $dispatcher);
        $reader = tick_thread_states(['implementer-thread' => 'running', 'reviewer-thread' => 'done']);
        app()->instance(AgentSnapshotReader::class, $reader);
        app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts([FakeTaskTurnReceipts::contents('changes_requested', 'Add the missing test.')]));

        app(TaskScheduler::class)->tick();

        expect($task->comments()->count())->toBe(0)
            ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
            ->and($task->fresh()?->communication_failures)->toBe(0)
            ->and($dispatcher->commands)->toBe([]);

        $reader->states['implementer-thread'] = 'idle';
        app(TaskScheduler::class)->tick();

        expect($task->fresh()?->status)->toBe(TaskStatus::Running)
            ->and($dispatcher->commands)->toHaveCount(1)
            ->and($dispatcher->commands[0]['message']['text'])->toContain('Add the missing test.');
    });

    it('commits an approval only once the implementer stops changing the workspace', function (): void {
        [$group, $task, , $signer] = tick_review([FakeTaskTurnReceipts::contents('approved', 'Checked the models.')]);
        $reader = tick_thread_states(['implementer-thread' => 'running', 'reviewer-thread' => 'done']);
        app()->instance(AgentSnapshotReader::class, $reader);
        app()->instance(AgentSpawner::class, new class implements AgentSpawner
        {
            public function reserveReviewer(Task $task): ?int
            {
                return null;
            }

            public function spawnReviewer(Task $task): ?int
            {
                return null;
            }

            public function spawnImplementer(Task $task): ?int
            {
                return test_agent_thread($task->parent, 'implementer-'.$task->id, $task)->id;
            }

            public function requestReview(Task $task): void {}
        });

        app(TaskScheduler::class)->tick();

        expect($signer->messages)->toBe([])
            ->and($task->comments()->count())->toBe(0)
            ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing);

        $reader->states['implementer-thread'] = 'idle';
        app(TaskScheduler::class)->tick();

        expect($signer->messages)->toBe(["Models\n\nChecked the models."])
            ->and($task->fresh()?->status)->toBe(TaskStatus::Completed);
    });
});

describe('topology paths at handoff', function (): void {
    beforeEach(function (): void {
        $this->topologyPaths = static function (Task $group): void {
            app()->instance(TaskVmSettings::class, new TaskVmSettings(
                enabled: false, devClusterId: null, wireguardRange: '10.44.0.128/25', hosts: [], modelProxyOrigin: null,
                piArtifactPath: null, piArtifactSha256: null, piModels: [],
                topologyPaths: [(int) $group->project_id => ['apps/agent/**', 'apps/gateway/app/Domain/Nodes/**']],
            ));
        };
    });

    it('refuses a vm handoff that changes a listed path while no subtask declares a topology', function (): void {
        [$group, $task] = tick_deliverables([], [], tick_evidence(['topology_diff' => [
            ['status' => 'M', 'path' => 'apps/agent/src/main.rs'],
            ['status' => 'M', 'path' => 'docs/reference/tasks.md'],
        ]]));
        ($this->topologyPaths)($group);

        $reminder = tick_deliverable_reminder();

        expect($task->fresh()?->status)->toBe(TaskStatus::Running)
            ->and($reminder)->toContain('This subtask changes paths that need real machines (apps/agent/src/main.rs), but no subtask of the group declares a topology');
    });

    it('passes a merge-main handoff whose Node changes are all inherited from main', function (): void {
        [$group, $task, $checks] = tick_deliverables([], [], tick_evidence([
            'diff' => [['status' => 'M', 'path' => 'apps/agent/src/main.rs']],
            'topology_diff' => [],
        ]));
        ($this->topologyPaths)($group);

        app(TaskScheduler::class)->tick();
        app(TaskScheduler::class)->tick();

        expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
            ->and($checks->deliverables)->toBe([['start' => null, 'commands' => [], 'topology' => true]]);
    });

    it('refuses a handoff when its topology diff cannot be read', function (): void {
        [$group, $task] = tick_deliverables([], [], tick_evidence(['topology_diff' => null]));
        ($this->topologyPaths)($group);

        $reminder = tick_deliverable_reminder();

        expect($task->fresh()?->status)->toBe(TaskStatus::Running)
            ->and($reminder)->toContain("Orbit could not read the group's diff against origin/main");
    });

    it('passes the handoff when a subtask of the group declares a topology or the change avoids the paths', function (string $case): void {
        $diff = $case === 'other path' ? [['status' => 'M', 'path' => 'docs/reference/tasks.md']] : [['status' => 'M', 'path' => 'apps/gateway/app/Domain/Nodes/RoleName.php']];
        [$group, $task] = tick_deliverables([], [], tick_evidence(['topology_diff' => $diff]));
        if ($case === 'declared') {
            Task::query()->create(['parent_id' => $group->id, 'position' => 9, 'title' => 'Prove', 'brief' => 'On nodes', 'status' => TaskStatus::Todo, 'topology' => ['app-dev']]);
            mock(TaskTopologyAdmission::class)->shouldReceive('prepare');
        }
        ($this->topologyPaths)($group);

        app(TaskScheduler::class)->tick();
        app(TaskScheduler::class)->tick();

        expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing);
    })->with(['declared', 'other path']);
});

describe('subtask deliverables at handoff', function (): void {
    it('asks the check for the diff and commands, then starts the reviewer when every deliverable passes', function (): void {
        [$group, $task, $checks, $receipts] = tick_deliverables(tick_all_deliverables(), tick_all_confirmations(), tick_evidence());

        app(TaskScheduler::class)->tick();
        app(TaskScheduler::class)->tick();

        expect($checks->deliverables)->toBe([[
            'start' => str_repeat('5', 40),
            'commands' => [
                ['id' => 'export-test', 'command' => 'vendor/bin/pest tests/Feature/ExportTest.php', 'directory' => 'apps/gateway'],
                ['id' => 'web-tests', 'command' => 'bun test', 'directory' => 'apps/web'],
            ],
        ]])
            ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
            ->and($task->comments()->sole()->deliverables)->toBe(tick_all_confirmations())
            ->and(TaskCheck::query()->sole()->deliverable_evidence)->toBe(tick_evidence())
            ->and($receipts->turnDeliverables)->toBe([['reference-page', 'export-test', 'web-tests', 'error-copy']]);
    });

    it('skips deliverables for a subtask without any', function (): void {
        [$group, $task, $checks] = tick_deliverables([], [], null);

        app(TaskScheduler::class)->tick();
        app(TaskScheduler::class)->tick();

        expect($checks->deliverables)->toBe([null])
            ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing);
    });

    it('returns a failing file deliverable to the implementer with the reason', function (array $diff, string $change, string $reason): void {
        $deliverable = ['id' => 'reference-page', 'type' => 'file', 'description' => 'Document the export', 'path' => 'docs/reference/*.md', 'change' => $change];
        [$group, $task] = tick_deliverables([$deliverable], ['reference-page' => 'Done'], ['start' => str_repeat('5', 40), 'diff' => $diff, 'commands' => []]);

        $reminder = tick_deliverable_reminder();

        expect($task->fresh()?->status)->toBe(TaskStatus::Running)
            ->and($reminder)->toContain("Orbit could not verify these deliverables:\n- reference-page (file): {$reason}")
            ->and($reminder)->toContain('--deliverable=ID=evidence for each deliverable of this subtask (reference-page)');
    })->with([
        'a missing path' => [[['status' => 'M', 'path' => 'docs/guides/tasks.md']], 'any', "no path in the subtask's diff matches docs/reference/*.md."],
        'a nested path the glob does not reach' => [[['status' => 'M', 'path' => 'docs/reference/cli/tasks.md']], 'modified', "no path in the subtask's diff matches docs/reference/*.md."],
        'a modified file that should be created' => [[['status' => 'M', 'path' => 'docs/reference/tasks.md']], 'created', 'the diff modifies docs/reference/tasks.md, but the deliverable needs docs/reference/*.md created.'],
        'a created file that should be modified' => [[['status' => 'A', 'path' => 'docs/reference/export.md']], 'modified', 'the diff adds docs/reference/export.md, but the deliverable needs docs/reference/*.md modified.'],
        'a deleted file' => [[['status' => 'D', 'path' => 'docs/reference/tasks.md']], 'any', 'the diff deletes docs/reference/tasks.md, but the deliverable needs docs/reference/*.md modified.'],
    ]);

    it('returns a failing command deliverable to the implementer with the reason', function (): void {
        $deliverable = ['id' => 'export-test', 'type' => 'command', 'description' => 'Test the export', 'command' => 'vendor/bin/pest tests/Feature/ExportTest.php', 'directory' => 'apps/gateway'];
        [$group, $task] = tick_deliverables([$deliverable], ['export-test' => 'ExportTest'], tick_evidence(['commands' => []]));

        $reminder = tick_deliverable_reminder();

        expect($task->fresh()?->status)->toBe(TaskStatus::Running)
            ->and($reminder)->toContain("- export-test (command): Orbit's check did not run `vendor/bin/pest tests/Feature/ExportTest.php` in apps/gateway.");
    });

    it('fails every mechanical deliverable when the check recorded no evidence', function (): void {
        [$group, $task] = tick_deliverables(tick_all_deliverables(), tick_all_confirmations(), null);

        expect(tick_deliverable_reminder())->toContain("Orbit's check recorded no evidence for the deliverables reference-page, export-test, web-tests.");
    });

    it('refuses a hand-written receipt that does not confirm every deliverable before the check runs', function (): void {
        [$group, $task, $checks] = tick_deliverables(tick_all_deliverables(), ['reference-page' => 'Export section'], tick_evidence());

        app(TaskScheduler::class)->tick();

        expect($checks->starts)->toBe(0)
            ->and(app(AgentCommandDispatcher::class)->commands[0]['message']['text'])->toContain('The turn receipt does not confirm the deliverables export-test, web-tests, error-copy. Pass --deliverable=ID=evidence for each one.');
    });

    it('asks for assistance when a deliverable still fails after the reminder', function (): void {
        $failing = FakeTaskCheckRunner::passed(tick_evidence(['commands' => [
            'export-test' => ['exit_code' => 0, 'output' => ''],
            'web-tests' => ['exit_code' => 2, 'output' => "boom\n"],
        ]]));
        [$group, $task, $checks] = tick_deliverables(
            tick_all_deliverables(),
            tick_all_confirmations(),
            null,
            [$failing, $failing],
            [FakeTaskTurnReceipts::contents('ready_for_review', 'Done.', null, tick_all_confirmations()), FakeTaskTurnReceipts::contents('ready_for_review', 'Fixed it.', null, tick_all_confirmations())],
        );

        tick_deliverable_reminder();
        app(TaskScheduler::class)->tick();
        app(TaskScheduler::class)->tick();

        expect($task->fresh()?->assistance_requested)->toBeTrue()
            ->and($group->fresh()?->assistance_reason)->toBe('Checks still failed after the reminder. Deliverable verification failed; see check #'.TaskCheck::query()->latest('id')->firstOrFail()->id.' for its evidence.')
            ->and($checks->starts)->toBe(2)
            ->and(app(AgentCommandDispatcher::class)->commands)->toHaveCount(1);
    });

    it('reminds a reviewer whose approval does not confirm each review deliverable', function (): void {
        [$group, $task, , $signer] = tick_review([FakeTaskTurnReceipts::contents('approved', 'Checked.', null, ['reference-page' => 'Read it'])]);
        $task->update(['deliverables' => tick_all_deliverables()]);

        app(TaskScheduler::class)->tick();

        expect($signer->messages)->toBe([])
            ->and(app(AgentCommandDispatcher::class)->commands[0]['message']['text'])->toContain('The turn receipt does not confirm the deliverables error-copy.')
            ->and(app(AgentCommandDispatcher::class)->commands[0]['message']['text'])->toContain('The approval must confirm each review deliverable (error-copy) with --deliverable=ID=evidence');
    });

    it('commits an approval that confirms each review deliverable', function (): void {
        [$group, $task, , $signer] = tick_review([FakeTaskTurnReceipts::contents('approved', 'Checked.', null, ['error-copy' => 'Each error names the subtask'])]);
        $task->update(['deliverables' => tick_all_deliverables()]);

        app(TaskScheduler::class)->tick();

        expect($signer->messages)->toBe(["Models\n\nChecked."])
            ->and($task->comments()->sole()->deliverables)->toBe(['error-copy' => 'Each error names the subtask']);
    });

    it('asks the check to prove a fails_on_base command and returns it when base also passes', function (): void {
        $deliverable = [
            'id' => 'layout-repro', 'type' => 'command', 'description' => 'The layout fails before the fix',
            'command' => 'vendor/bin/pest tests/Feature/HomeScreenTest.php', 'directory' => 'apps/gateway',
            'fails_on_base' => true, 'paths' => ['apps/gateway/tests/Feature/HomeScreenTest.php'],
        ];
        $evidence = tick_evidence(['commands' => [
            'layout-repro' => ['base_started' => true, 'base_exit_code' => 0, 'base_output' => '', 'exit_code' => 0, 'output' => ''],
        ]]);
        [$group, $task, $checks] = tick_deliverables([$deliverable], ['layout-repro' => 'The command is in the Pest file'], $evidence);

        app(TaskScheduler::class)->tick();
        app(TaskScheduler::class)->tick();

        expect($task->fresh()?->status)->toBe(TaskStatus::Running)
            ->and($checks->deliverables)->toBe([[
                'start' => str_repeat('5', 40),
                'commands' => [[
                    'id' => 'layout-repro',
                    'command' => 'vendor/bin/pest tests/Feature/HomeScreenTest.php',
                    'directory' => 'apps/gateway',
                    'fails_on_base' => true,
                    'paths' => ['apps/gateway/tests/Feature/HomeScreenTest.php'],
                ]],
            ]])
            ->and(app(AgentCommandDispatcher::class)->commands[0]['message']['text'])->toContain('also exited 0 on the start commit');
    });

    it('asks for assistance when a command overlay path is invalid, without reminding the implementer', function (): void {
        $reason = 'Deliverable sweep-test names invalid overlay path missing.sh.';
        $failed = TaskCheckReading::finished(1, str_repeat('a', 40), str_repeat('b', 40), [], $reason."\nTOKEN=synthetic-secret-value\n", failedStep: 'invalid_deliverable');
        [$group, $task, $checks] = tick_deliverables(
            [[
                'id' => 'sweep-test',
                'type' => 'command',
                'description' => 'Repro the sweep',
                'command' => 'vendor/bin/pest tests/Feature/SweepTest.php',
                'directory' => 'apps/gateway',
                'fails_on_base' => true,
                'paths' => ['missing.sh'],
            ]],
            ['sweep-test' => 'The check names the missing path'],
            null,
            [$failed],
        );

        app(TaskScheduler::class)->tick();
        app(TaskScheduler::class)->tick();

        expect($group->fresh()?->assistance_requested)->toBeTrue()
            ->and($group->fresh()?->assistance_reason)->toBe('Command deliverable validation failed in check #'.TaskCheck::query()->sole()->id.'; see its output for the invalid directory or overlay path.')
            ->and($group->fresh()?->assistance_reason)->not->toContain('synthetic-secret-value')
            ->and($task->fresh()?->assistance_reason)->toBe($group->fresh()?->assistance_reason)
            ->and(TaskCheck::query()->sole()->output)->toContain($reason, 'TOKEN=[REDACTED]')
            ->and($task->fresh()?->assistance_reason)->not->toContain('synthetic-secret-value')
            ->and(app(AgentCommandDispatcher::class)->commands)->toBe([])
            ->and($checks->starts)->toBe(1)
            ->and(TaskCheck::query()->sole()->status)->toBe(TaskCheckStatus::Failed)
            ->and(TaskCheck::query()->sole()->failed_step)->toBe('invalid_deliverable');
    });

    it('asks for assistance for an invalid deliverable even when the tree changed', function (): void {
        $reason = 'Deliverable sweep-test names invalid overlay path missing.sh.';
        $failed = TaskCheckReading::finished(1, str_repeat('d', 40), str_repeat('c', 40), ['app'], $reason."\nTOKEN=synthetic-secret-value\n", failedStep: 'invalid_deliverable');
        [$group, $task, $checks] = tick_deliverables(
            [[
                'id' => 'sweep-test',
                'type' => 'command',
                'description' => 'Repro the sweep',
                'command' => 'vendor/bin/pest tests/Feature/SweepTest.php',
                'directory' => 'apps/gateway',
                'fails_on_base' => true,
                'paths' => ['missing.sh'],
            ]],
            ['sweep-test' => 'The check names the missing path'],
            null,
            [$failed],
        );

        app(TaskScheduler::class)->tick();
        app(TaskScheduler::class)->tick();

        expect($group->fresh()?->assistance_requested)->toBeTrue()
            ->and($group->fresh()?->assistance_reason)->toBe('Command deliverable validation failed in check #'.TaskCheck::query()->sole()->id.'; see its output for the invalid directory or overlay path.')
            ->and($group->fresh()?->assistance_reason)->not->toContain('synthetic-secret-value')
            ->and($task->fresh()?->assistance_reason)->toBe($group->fresh()?->assistance_reason)
            ->and(TaskCheck::query()->sole()->output)->toContain($reason, 'TOKEN=[REDACTED]')
            ->and($task->fresh()?->assistance_reason)->not->toContain('synthetic-secret-value')
            ->and(app(AgentCommandDispatcher::class)->commands)->toBe([])
            ->and($checks->starts)->toBe(1)
            ->and(TaskCheck::query()->sole()->status)->toBe(TaskCheckStatus::Failed)
            ->and(TaskCheck::query()->sole()->failed_step)->toBe('invalid_deliverable')
            ->and(TaskCheck::query()->sole()->changed_paths)->toBe(['app']);
    });
});

it('project baseline setup only', function (): void {
    $spawner = new class implements AgentSpawner
    {
        /** @var list<string> */
        public array $events = [];

        public function reserveReviewer(Task $task): ?int
        {

            return null;
        }

        public function spawnReviewer(Task $task): ?int
        {
            $this->events[] = 'reviewer';

            return null;
        }

        public function spawnImplementer(Task $task): ?int
        {
            $this->events[] = 'implementer:'.$task->position;

            return test_agent_thread($task->parent, 'implementer-'.$task->position, $task)->id;
        }

        public function requestReview(Task $task): void
        {
            $this->events[] = 'review:'.$task->position;
        }
    };
    app()->instance(AgentSpawner::class, $spawner);
    app(TaskExtensionState::class)->enable();

    $composer = tick_baseline_group('baseline-composer', 'composer check', [
        ['name' => 'Warm cache', 'command' => 'echo warm', 'timeout_seconds' => 30, 'position' => 2],
        ['name' => 'Install', 'command' => 'composer install --no-interaction', 'timeout_seconds' => 900, 'position' => 1],
    ], '10.51.0.1');
    ProjectLifecycleStep::query()->create([
        'project_id' => $composer->project_id,
        'phase' => 'teardown',
        'name' => 'Remove bridge',
        'command' => 'echo teardown',
        'timeout_seconds' => 60,
        'position' => 1,
    ]);
    $javascript = tick_baseline_group('baseline-javascript', 'vp run check', [
        ['name' => 'Install packages', 'command' => 'vp install --frozen-lockfile', 'timeout_seconds' => 120, 'position' => 1],
    ], '10.51.0.2');
    $unset = tick_baseline_group('baseline-unset-check', null, [
        ['name' => 'Prepare', 'command' => 'echo prepare', 'timeout_seconds' => 45, 'position' => 1],
    ], '10.51.0.3');
    $checks = new FakeTaskCheckRunner;
    app()->instance(TaskCheckRunner::class, $checks);

    app(TaskScheduler::class)->tick();

    expect($checks->commands)->toBe(['composer check', 'vp run check', null])
        ->and($checks->setups)->toBe([
            [
                ['name' => 'Install', 'command' => 'composer install --no-interaction', 'timeout_seconds' => 900],
                ['name' => 'Warm cache', 'command' => 'echo warm', 'timeout_seconds' => 30],
            ],
            [
                ['name' => 'Install packages', 'command' => 'vp install --frozen-lockfile', 'timeout_seconds' => 120],
            ],
            [
                ['name' => 'Prepare', 'command' => 'echo prepare', 'timeout_seconds' => 45],
            ],
        ])
        ->and($spawner->events)->toBe([]);

    $composer->update(['status' => TaskGroupStatus::Completed]);
    $javascript->update(['status' => TaskGroupStatus::Completed]);

    app(TaskScheduler::class)->tick();

    expect($unset->fresh()?->assistance_requested)->toBeFalse()
        ->and($unset->tasks()->value('implementer_agent_thread_id'))->not->toBeNull()
        ->and($spawner->events)->toBe(['implementer:1']);

    $unset->update(['status' => TaskGroupStatus::Completed]);
    $failedSetup = tick_baseline_group('baseline-setup-failed', 'composer check', [
        ['name' => 'Install', 'command' => 'composer install --no-interaction', 'timeout_seconds' => 600, 'position' => 1],
    ], '10.51.0.4');
    $setupOutput = "setup blew up\n";
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner([
        TaskCheckReading::finished(7, str_repeat('a', 40), str_repeat('b', 40), [], $setupOutput, null, str_repeat('b', 40), 'Install'),
    ]));

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $failedTaskId = $failedSetup->tasks()->value('id');
    expect($failedSetup->fresh()?->assistance_requested)->toBeTrue()
        ->and($failedSetup->fresh()?->assistance_reason)->toBe('The Project setup step "Install" failed with exit code 7 on a fresh checkout of task-'.$failedSetup->id.', before any agent started. Fix the setup or the branch, then post a resolution on this subtask to retry the baseline. The task\'s check shows the output.')
        ->and(TaskCheck::query()->where('task_id', $failedTaskId)->sole()->output)->toBe($setupOutput)
        ->and($failedSetup->tasks()->value('implementer_agent_thread_id'))->toBeNull()
        ->and($spawner->events)->toBe(['implementer:1']);

    $failedSetup->update(['status' => TaskGroupStatus::Completed]);
    $ordinary = tick_baseline_group('baseline-ordinary-failure', 'composer check', [], '10.51.0.5');
    $ordinaryOutput = "sh: 1: vendor/bin/pest: not found\nsh: 1: node_modules/.bin/vite: not found\n";
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner([
        TaskCheckReading::finished(1, str_repeat('a', 40), str_repeat('b', 40), [], $ordinaryOutput, null, str_repeat('b', 40), null),
    ]));

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $reason = $ordinary->fresh()?->assistance_reason;
    expect($ordinary->fresh()?->assistance_requested)->toBeTrue()
        ->and($reason)->toBe('The Project baseline check failed with exit code 1 on a fresh checkout of task-'.$ordinary->id.', before any agent started. Fix the configured check or the branch, then post a resolution on this subtask to retry the baseline. The task\'s check shows the output.')
        ->and($reason)->not->toContain('Project dependencies appear to be missing')
        ->and($reason)->not->toContain('Composer dependency installation failed')
        ->and($reason)->not->toContain('JavaScript dependency installation failed')
        ->and(TaskCheck::query()->where('task_id', $ordinary->tasks()->value('id'))->sole()->output)->toBe($ordinaryOutput)
        ->and($spawner->events)->toBe(['implementer:1']);
});

it('retries a failed baseline on resolution at the current default branch tip without recreating the group', function (): void {
    app(TaskExtensionState::class)->enable();
    $group = tick_baseline_group('baseline-resolution', 'composer check', [], '10.51.0.6');
    $agents = tick_running_agents();
    $runner = new FakeTaskCheckRunner([
        TaskCheckReading::finished(1, str_repeat('a', 40), str_repeat('b', 40), [], "baseline failed\n"),
    ]);
    app()->instance(TaskCheckRunner::class, $runner);
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    $task = $group->tasks()->sole();
    $failed = TaskCheck::query()->where('task_id', $task->id)->sole();
    expect($task->assistance_requested)->toBeTrue()
        ->and($task->assistance_kind)->toBe(AssistanceKind::Failure);
    $task->update(['subtask_start_commit' => str_repeat('a', 40)]);
    // The remote default branch advanced after the first baseline failed.
    $tip = str_repeat('c', 40);
    $fetcher = mock(TaskBaseBranchFetcher::class);
    $fetcher->shouldReceive('fetchForTurn')->once()->ordered();
    $fetcher->shouldReceive('resetToDefault')->once()->ordered()->andReturn($tip);

    $resolution = app(StoreTaskCommentAction::class)->execute($task, [
        'type' => 'resolution', 'body' => 'The default branch check is fixed. Retry.', 'author' => 'operator',
    ]);

    $this->assertDatabaseHas('tasks', [
        'id' => $task->id, 'status' => 'running', 'subtask_start_commit' => $tip,
        'assistance_requested' => false, 'assistance_reason' => null,
        'assistance_kind' => null, 'assistance_question' => null,
        'resolution_delivered_comment_id' => $resolution->id, 'implementer_agent_thread_id' => null,
    ]);
    $this->assertDatabaseHas('tasks', [
        'id' => $group->id, 'status' => 'running', 'assistance_requested' => false,
        'assistance_kind' => null, 'assistance_question' => null, 'assistance_reason' => null,
    ]);
    expect($runner->starts)->toBe(1);
    expect($failed->fresh()?->output)->toBe("baseline failed\n");
    expect($failed->fresh()?->status)->toBe(TaskCheckStatus::Failed);

    app(TaskScheduler::class)->tick();

    expect($runner->starts)->toBe(2);
    expect(TaskCheck::query()->where('task_id', $task->id)->count())->toBe(2);
    $this->assertDatabaseHas('task_checks', ['task_id' => $task->id, 'kind' => 'baseline', 'status' => 'running']);
    expect($agents->spawned)->toBe([]);

    app()->instance(TaskBaseBranchFetcher::class, $agents);
    app(TaskScheduler::class)->tick();

    expect($agents->spawned)->toBe([$task->id]);
    expect($task->fresh()?->subtask_start_commit)->toBe($tip);
});

it('takes no baseline retry lease for an earlier resolution during a later assistance hold', function (bool $tick): void {
    app(TaskExtensionState::class)->enable();
    $group = tick_baseline_group('resolved-baseline', 'composer check', [], '10.51.0.20');
    $agents = tick_running_agents();
    $runner = new FakeTaskCheckRunner([
        TaskCheckReading::finished(1, str_repeat('a', 40), str_repeat('b', 40), [], "baseline failed\n"),
    ]);
    app()->instance(TaskCheckRunner::class, $runner);
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    $task = $group->tasks()->sole();
    $fetcher = mock(TaskBaseBranchFetcher::class);
    $fetcher->shouldReceive('fetchForTurn')->once();
    $fetcher->shouldReceive('resetToDefault')->once()->andReturn(str_repeat('c', 40));
    app(StoreTaskCommentAction::class)->execute($task, [
        'type' => 'resolution', 'body' => 'Retry the baseline.', 'author' => 'operator',
    ]);
    app(TaskScheduler::class)->tick();
    app()->instance(TaskBaseBranchFetcher::class, $agents);
    app(TaskScheduler::class)->tick();
    TaskAssistance::apply($task, AssistanceKind::Failure, null, 'Operator hold after the implementer started.');
    TaskAssistance::apply($group, AssistanceKind::Failure, null, 'Operator hold after the implementer started.');
    $leases = TaskWorkspaceLeaseRecord::query()->where('group_id', $group->id)->count();

    if ($tick) {
        app(TaskScheduler::class)->tick();
        app(TaskScheduler::class)->tick();
    } else {
        // Direct callers must also reject the resolved request before workspace admission.
        expect(app(RetryTaskBaselineAction::class)->recover($task))->toBeFalse();
    }

    expect(TaskWorkspaceLeaseRecord::query()->where('group_id', $group->id)->count())->toBe($leases);
    expect($runner->starts)->toBe(2);
    expect($task->fresh()->assistance_requested)->toBeTrue();
})->with(['scheduler tick' => true, 'direct recovery' => false]);

it('waits for a new resolution after a failed retry and starts a third baseline', function (): void {
    app(TaskExtensionState::class)->enable();
    $group = tick_baseline_group('consumed-baseline-resolution', 'composer check', [], '10.51.0.21');
    $runner = new FakeTaskCheckRunner([
        TaskCheckReading::finished(1, str_repeat('a', 40), str_repeat('b', 40), [], "first failure\n"),
        TaskCheckReading::finished(1, str_repeat('c', 40), str_repeat('b', 40), [], "retry failure\n"),
    ]);
    app()->instance(TaskCheckRunner::class, $runner);
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    $task = $group->tasks()->sole();
    $fetcher = mock(TaskBaseBranchFetcher::class);
    $fetcher->shouldReceive('fetchForTurn')->twice();
    $fetcher->shouldReceive('resetToDefault')->twice()->andReturn(str_repeat('c', 40), str_repeat('d', 40));
    $resolution = app(StoreTaskCommentAction::class)->execute($task, [
        'type' => 'resolution', 'body' => 'Retry the baseline.', 'author' => 'operator',
    ]);
    $runner->head = str_repeat('c', 40);
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    $failed = $task->checks()->latest('id')->first();
    expect($failed->status)->toBe(TaskCheckStatus::Failed);
    expect($failed->task_comment_id)->toBe($resolution->id);
    $leases = TaskWorkspaceLeaseRecord::query()->where('group_id', $group->id)->count();

    app(TaskScheduler::class)->tick();
    expect(app(RetryTaskBaselineAction::class)->recover($task))->toBeFalse();

    expect(TaskWorkspaceLeaseRecord::query()->where('group_id', $group->id)->count())->toBe($leases);
    expect($runner->starts)->toBe(2);
    expect($task->fresh()->assistance_requested)->toBeTrue();

    $secondResolution = app(StoreTaskCommentAction::class)->execute($task, [
        'type' => 'resolution', 'body' => 'The remaining failure is fixed. Retry again.', 'author' => 'operator',
    ]);
    expect($task->fresh()->subtask_start_commit)->toBe(str_repeat('d', 40));
    expect($task->fresh()->resolution_delivered_comment_id)->toBe($secondResolution->id);
    expect($task->fresh()->assistance_requested)->toBeFalse();
    expect($group->fresh()->assistance_requested)->toBeFalse();
    $runner->head = str_repeat('d', 40);

    app(TaskScheduler::class)->tick();

    $third = $task->checks()->where('kind', TaskCheckKind::Baseline)->latest('id')->first();
    expect($runner->starts)->toBe(3);
    expect($third->status)->toBe(TaskCheckStatus::Running);
    expect($third->head_before)->toBe(str_repeat('d', 40));
    expect($third->task_comment_id)->toBe($secondResolution->id);
    expect($task->checks()->where('kind', TaskCheckKind::Baseline)->count())->toBe(3);
});

it('keeps baseline assistance and evidence when retry preparation fails', function (string $step): void {
    app(TaskExtensionState::class)->enable();
    $group = tick_baseline_group('baseline-retry-failed', 'composer check', [], '10.51.0.7');
    $runner = new FakeTaskCheckRunner([
        TaskCheckReading::finished(1, str_repeat('a', 40), str_repeat('b', 40), [], "baseline failed\n"),
    ]);
    app()->instance(TaskCheckRunner::class, $runner);
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    $task = $group->tasks()->sole();
    $task->update(['subtask_start_commit' => str_repeat('a', 40)]);
    $fetcher = mock(TaskBaseBranchFetcher::class);
    if ($step === 'fetch') {
        $fetcher->shouldReceive('fetchForTurn')->twice()->andThrow(new TaskPullRequestException('Fetch failed.'));
        $fetcher->shouldNotReceive('resetToDefault');
    } else {
        $fetcher->shouldReceive('fetchForTurn')->twice();
        $fetcher->shouldReceive('resetToDefault')->twice()->andThrow(new TaskPullRequestException('Reset failed.'));
    }

    $resolution = app(StoreTaskCommentAction::class)->execute($task, [
        'type' => 'resolution', 'body' => 'Retry the baseline.', 'author' => 'operator',
    ]);
    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('tasks', [
        'id' => $task->id, 'assistance_requested' => true, 'subtask_start_commit' => str_repeat('a', 40),
        'resolution_delivered_comment_id' => $resolution->id, 'implementer_agent_thread_id' => null,
    ]);
    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'assistance_requested' => true]);
    expect(TaskCheck::query()->where('task_id', $task->id)->sole()->output)->toBe("baseline failed\n");
    expect($runner->starts)->toBe(1);

    // A pending failure resolution must not reset the workspace after the operator asks for direction.
    $record = $step === 'fetch' ? $task : $group;
    TaskAssistance::apply($record, AssistanceKind::Direction, 'Which setup should this Project use?', 'Which setup should this Project use?');
    expect(app(RetryTaskBaselineAction::class)->recover($task))->toBeFalse();
    $this->assertDatabaseHas('tasks', [
        'id' => $record->id, 'assistance_requested' => true, 'assistance_kind' => 'direction',
        'assistance_question' => 'Which setup should this Project use?',
    ]);
})->with(['fetch', 'reset']);

it('recovers a baseline retry after an interrupted reset transition without another resolution', function (string $failure): void {
    app(TaskExtensionState::class)->enable();
    $group = tick_baseline_group('baseline-recovery', 'composer check', [], '10.51.0.9');
    $agents = tick_running_agents();
    $runner = new FakeTaskCheckRunner([
        TaskCheckReading::finished(1, str_repeat('a', 40), str_repeat('b', 40), [], "baseline failed\n"),
    ]);
    app()->instance(TaskCheckRunner::class, $runner);
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    $task = $group->tasks()->sole();
    $head = str_repeat('a', 40);
    $tip = str_repeat('c', 40);
    $task->update(['subtask_start_commit' => $head]);
    $fetcher = mock(TaskBaseBranchFetcher::class);
    $fetcher->shouldReceive('fetchForTurn')->twice();
    $resets = 0;
    $fetcher->shouldReceive('resetToDefault')->twice()->andReturnUsing(function () use (&$head, $tip, &$resets, $failure): string {
        $head = $tip;
        $resets++;
        if ($resets === 1 && $failure === 'lost response') {
            throw new TaskPullRequestException('Reset succeeded but its reply was lost.');
        }

        return $head;
    });
    if ($failure === 'bookkeeping rollback') {
        DB::statement("CREATE TRIGGER fail_baseline_retry_bookkeeping BEFORE UPDATE OF subtask_start_commit ON tasks WHEN NEW.subtask_start_commit = '$tip' BEGIN SELECT RAISE(ABORT, 'baseline retry bookkeeping failed'); END");
        expect(fn () => app(StoreTaskCommentAction::class)->execute($task, [
            'type' => 'resolution', 'body' => 'Retry the baseline.', 'author' => 'operator',
        ]))->toThrow(QueryException::class, 'baseline retry bookkeeping failed');
        DB::statement('DROP TRIGGER fail_baseline_retry_bookkeeping');
    } else {
        app(StoreTaskCommentAction::class)->execute($task, [
            'type' => 'resolution', 'body' => 'Retry the baseline.', 'author' => 'operator',
        ]);
    }
    $resolution = TaskComment::query()->where('task_id', $task->id)->where('type', 'resolution')->sole();
    expect($head)->toBe($tip);
    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'subtask_start_commit' => str_repeat('a', 40),
        'assistance_requested' => true, 'resolution_delivered_comment_id' => $resolution->id]);
    expect($runner->starts)->toBe(1);

    // A new scheduler instance represents the next Gateway process. No new resolution is posted.
    app()->forgetInstance(TaskScheduler::class);
    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'subtask_start_commit' => $head, 'assistance_requested' => false]);
    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'assistance_requested' => false]);
    expect($runner->starts)->toBe(2);
    expect($agents->spawned)->toBe([]);
    app()->instance(TaskBaseBranchFetcher::class, $agents);
    app(TaskScheduler::class)->tick();

    expect($resets)->toBe(2);
    expect($runner->starts)->toBe(2);
    expect(TaskCheck::query()->where('task_id', $task->id)->count())->toBe(2);
    expect($agents->spawned)->toBe([$task->id]);
    expect(TaskComment::query()->where('task_id', $task->id)->where('type', 'resolution')->count())->toBe(1);
})->with(['lost response', 'bookkeeping rollback']);

it('never resets a failed baseline workspace after an implementer has started elsewhere in the group', function (): void {
    app(TaskExtensionState::class)->enable();
    $group = tick_baseline_group('baseline-touched', 'composer check', [], '10.51.0.8');
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner([
        TaskCheckReading::finished(1, str_repeat('a', 40), str_repeat('b', 40), [], "baseline failed\n"),
    ]));
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    $task = $group->tasks()->sole();
    $other = Task::query()->create([
        'parent_id' => $group->id, 'position' => 2, 'title' => 'Earlier work', 'brief' => 'Done.', 'status' => TaskStatus::Completed,
    ]);
    test_agent_thread($group, 'earlier-implementer', $other);
    $fetcher = mock(TaskBaseBranchFetcher::class);
    $fetcher->shouldNotReceive('fetchForTurn');
    $fetcher->shouldNotReceive('resetToDefault');

    app(StoreTaskCommentAction::class)->execute($task, [
        'type' => 'resolution', 'body' => 'Retry the baseline.', 'author' => 'operator',
    ]);

    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'assistance_requested' => true, 'resolution_delivered_comment_id' => null]);
    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'assistance_requested' => true]);
});

it('queues the baseline retry when main was red and its green tip contains the failed commit', function (): void {
    // Task 1293: the baseline failed on red main 82fcdd38, and main then turned green at e43566fe.
    $failed = '82fcdd38'.str_repeat('0', 32);
    $tip = 'e43566fe'.str_repeat('0', 32);
    [$group, $task, $runner] = tick_red_main_baseline('baseline-red-main', $failed, $tip, 'failure', 'success');
    $fetcher = mock(TaskBaseBranchFetcher::class);
    $fetcher->shouldReceive('fetchForTurn')->once()->ordered();
    $fetcher->shouldReceive('resetToDefault')->once()->ordered()->andReturn($tip);

    app(TaskScheduler::class)->tick();

    $resolution = TaskComment::query()->where('task_id', $task->id)->where('type', 'resolution')->sole();
    expect($resolution->author)->toBe('orbit')
        ->and($resolution->body)->toBe('The baseline failed on '.$failed.', where Required checks failed. The default branch tip '.$tip.' contains it and passed Required checks, so Orbit retries the baseline.');
    $this->assertDatabaseHas('tasks', [
        'id' => $task->id, 'subtask_start_commit' => $tip, 'assistance_requested' => false,
        'resolution_delivered_comment_id' => $resolution->id, 'implementer_agent_thread_id' => null,
    ]);
    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'assistance_requested' => false]);

    app(TaskScheduler::class)->tick();

    expect($runner->starts)->toBe(2);
});

it('keeps baseline assistance unless main was red and its tip is green and contains the failed commit', function (string $failedConclusion, string $tipConclusion, string $status): void {
    $failed = '82fcdd38'.str_repeat('0', 32);
    [$group, $task, $runner] = tick_red_main_baseline('baseline-not-red-main', $failed, 'e43566fe'.str_repeat('0', 32), $failedConclusion, $tipConclusion, $status);
    $fetcher = mock(TaskBaseBranchFetcher::class);
    $fetcher->shouldNotReceive('fetchForTurn');
    $fetcher->shouldNotReceive('resetToDefault');

    app(TaskScheduler::class)->tick();

    expect(TaskComment::query()->where('task_id', $task->id)->where('type', 'resolution')->exists())->toBeFalse()
        ->and($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($group->fresh()?->assistance_requested)->toBeTrue()
        ->and($runner->starts)->toBe(1);
})->with([
    'main was green' => ['success', 'success', 'ahead'],
    'tip pending' => ['failure', 'pending', 'ahead'],
    'tip red' => ['failure', 'failure', 'ahead'],
    'tip does not contain the failed commit' => ['failure', 'success', 'diverged'],
]);

it('updates a behind pull request without creating an agent fixup and waits for the new head', function (TaskGroupStatus $status): void {
    $group = tick_settling_group();
    $group->update(['status' => $status]);
    $agents = tick_running_agents();
    // Captured from the disposable GitHub proof PR #973 (2026-10-07).
    tick_watch_pulls([tick_open_pull(['mergeable_state' => 'behind']), tick_open_pull(['mergeable_state' => 'behind'])], ['abc123' => []], 202, 'Updating pull request branch.');

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($group->fresh()->status)->toBe($status)
        ->and($group->tasks()->whereNotNull('fixup_problem')->count())->toBe(0)
        ->and($agents->spawned)->toBe([]);
    $updates = Http::recorded(static fn (Request $request): bool => str_ends_with($request->url(), '/update-branch'));
    expect($updates)->toHaveCount(1);
    expect($updates->first()[0]->method())->toBe('PUT')
        ->and($updates->first()[0]->data())->toBe(['expected_head_sha' => 'abc123']);
})->with([TaskGroupStatus::Settling, TaskGroupStatus::WaitingForReview]);

it('waits visibly without a fixup when a branch update is refused without a merge conflict', function (int $status, string $message): void {
    $group = tick_settling_group();
    tick_watch_pulls([tick_open_pull(['mergeable' => false, 'mergeable_state' => 'dirty'])], ['abc123' => []], $status, $message);

    app(TaskScheduler::class)->tick();

    expect($group->fresh()->status)->toBe(TaskGroupStatus::Settling)
        ->and($group->tasks()->whereNotNull('fixup_problem')->count())->toBe(0)
        ->and($group->fresh()->assistance_reason)->toContain('GitHub could not update');
})->with([
    'stale head' => [422, 'Expected head sha did not match'],
    'permission' => [403, 'Resource not accessible by integration'],
    'outage' => [503, 'Service Unavailable'],
]);

it('scopes every Orbit handoff test run to the subtask start', function (int $position, bool $later, bool $orbit): void {
    [$group, $task, $checks] = tick_checking([TaskCheckReading::running()]);
    $group->project->update(['slug' => $orbit ? 'orbit' : 'other']);
    $task->update(['position' => $position, 'subtask_start_commit' => str_repeat('a', 40)]);
    if ($position > 1) {
        Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Earlier', 'brief' => 'Done.', 'status' => TaskStatus::Completed]);
    }
    if ($later) {
        Task::query()->create(['parent_id' => $group->id, 'position' => $position + 1, 'title' => 'Later', 'brief' => 'Next.', 'status' => TaskStatus::Todo]);
    }

    app(TaskScheduler::class)->tick();

    expect($checks->starts)->toBe(1)
        ->and($checks->deliverables[0]['test_base'] ?? null)->toBe($orbit ? str_repeat('a', 40) : null);
})->with([
    'intermediate Orbit subtask' => [1, true, true],
    'last Orbit subtask' => [2, false, true],
    'single-subtask Orbit group' => [1, false, true],
    'intermediate non-Orbit subtask' => [1, true, false],
    'single-subtask non-Orbit group' => [1, false, false],
]);

it('advances rework tests only to the latest passed handoff tree of the same subtask', function (bool $fixup): void {
    [$group, $task, $checks] = tick_checking([TaskCheckReading::running()]);
    $group->project->update(['slug' => 'orbit']);
    $task->update(['subtask_start_commit' => str_repeat('a', 40), 'completion_attempt' => 2,
        'fixup_problem' => $fixup ? 'final-review' : null]);
    foreach ([TaskCheckStatus::Passed, TaskCheckStatus::Failed, TaskCheckStatus::Changed, TaskCheckStatus::Lost, TaskCheckStatus::Cancelled] as $index => $status) {
        $historical = TaskCheck::query()->create([
            'task_id' => $task->id, 'kind' => TaskCheckKind::Handoff, 'status' => $status,
            'pid' => 1, 'process_started' => 'earlier', 'head_before' => str_repeat('a', 40),
            'tree_before' => str_repeat('b', 40), 'tree_after' => str_repeat((string) ($index + 1), 40),
            'started_at' => now(),
        ]);
        if ($status === TaskCheckStatus::Lost) {
            // This historical process was confirmed stopped before the current rework attempt.
            TaskWorkspaceLeaseRecord::query()->create(['instance_id' => $group->taskable_id, 'group_id' => $group->id,
                'kind' => 'check', 'owner' => 'historical:'.$historical->id, 'check_id' => $historical->id,
                'status' => 'released', 'released_at' => now()]);
        }
    }
    $other = Task::query()->create(['parent_id' => $group->id, 'position' => 2, 'title' => 'Other', 'brief' => 'Done.', 'status' => TaskStatus::Completed]);
    TaskCheck::query()->create(['task_id' => $other->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Passed,
        'pid' => 1, 'process_started' => 'other', 'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('f', 40), 'started_at' => now()]);

    app(TaskScheduler::class)->tick();

    expect($checks->starts)->toBe(1)
        ->and($checks->deliverables[0]['test_base'])->toBe(str_repeat('1', 40))
        ->and($checks->deliverables[0]['start'])->toBe(str_repeat('a', 40));
})->with(['requested changes' => false, 'final-review fixup' => true]);

it('preserves another parent failure or sibling request when a baseline resolution completes', function (string $other): void {
    app(TaskExtensionState::class)->enable();
    $group = tick_baseline_group('baseline-parent-assistance', 'composer check', [], '10.51.0.6');
    tick_running_agents();
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner([
        TaskCheckReading::finished(1, str_repeat('a', 40), str_repeat('b', 40), [], "baseline failed\n"),
    ]));
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    $task = $group->tasks()->sole();
    if ($other === 'failure') {
        test_update_task($group, test_assistance_attributes(AssistanceKind::Failure, null, 'Unrelated parent failure.'));
    } else {
        test_create_task($group->tasks(), [
            'title' => 'Still blocked', 'brief' => 'Keep this request.', 'position' => 2, 'status' => TaskStatus::Todo,
            ...test_assistance_attributes(AssistanceKind::Failure, null, 'Another failure.'),
        ]);
    }
    $before = $group->fresh()->only(array_keys(['assistance_requested' => false, 'assistance_kind' => null, 'assistance_question' => null, 'assistance_reason' => null]));
    $fetcher = mock(TaskBaseBranchFetcher::class);
    $fetcher->shouldReceive('fetchForTurn')->once();
    $fetcher->shouldReceive('resetToDefault')->once()->andReturn(str_repeat('c', 40));

    app(StoreTaskCommentAction::class)->execute($task, [
        'type' => 'resolution', 'body' => 'Retry the baseline.', 'author' => 'operator',
    ]);

    expect($task->fresh()->assistance_requested)->toBeFalse();
    expect($group->fresh()->only(array_keys($before)))->toBe($before);
})->with(['failure', 'sibling']);

it('redacts command-deliverable failure evidence before reminding the implementer', function (): void {
    tick_deliverables(
        tick_all_deliverables(), tick_all_confirmations(),
        tick_evidence(['commands' => [
            'export-test' => ['exit_code' => 0, 'output' => ''],
            'web-tests' => ['exit_code' => 2, 'output' => 'TOKEN='.str_repeat('s', 40_000)."\nLast line"],
        ]]),
    );

    tick_deliverable_reminder();

    $message = app(AgentCommandDispatcher::class)->commands[0]['message']['text'];
    expect($message)->toContain('web-tests', 'TOKEN=[REDACTED]', 'Last line')
        ->not->toContain('ssss');
});
