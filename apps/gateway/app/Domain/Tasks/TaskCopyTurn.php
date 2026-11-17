<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Data\Tasks\TaskTurnData;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\AgentThread;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Support\ValidatedData;
use Illuminate\Support\Str;

/**
 * The admission of one agent turn in a task copy (docs/cli/task/copy-turn.mmd). Swamp runs T3's own `t3 thread` command in the copy as
 * `agent`; T3 issues and revokes its session inside the container, so the intent names no credential and no vault key.
 */
final readonly class TaskCopyTurn
{
    /**
     * The driver of a copy turn's thread. Orbit stops a copy's run only inside a turn's phase, through the stock T3 server in the copy:
     * a run that asks, a run cut by the turn's bound, and the run of an earlier turn that is over in Orbit. The registry holds no driver
     * for it.
     */
    public const string Driver = 't3';

    /** How long the agent's turn may run before Swamp ends its wait; the bound of a task VM turn. */
    public const int TurnSeconds = 3600;

    /** How an implementer in a copy asks: T3 has no other way to hand a question back, and Orbit ends the turn at it. */
    public const string AskRule = 'To ask a question, use your question tool. Asking ends this turn; the answer comes as the next turn.';

    /**
     * What a reviewer in a copy judges, whatever its provider (docs/reference/tasks.md#the-reviewer-rule): the task's scope, every finding
     * inside it, nothing outside it. Its approval leads into PrTextRule, which follows it.
     */
    public const string ReviewRule = 'Judge only the task\'s scope: the brief, its deliverables, the diff of the checked commit against the task\'s base, '
        .'and what that diff directly affects. Report every finding inside that scope. A blocking finding is a defect: wrong behaviour, a missed or '
        .'unproven deliverable, a broken or missing test the brief asks for, or a security hole the diff adds. Give its file, line and failing case. '
        .'A finding inside the scope that is not a defect, such as a misleading name in the new code, is non-blocking: mark it so. Report nothing '
        .'outside the scope: no ideas, refactors or redesigns beyond the task. Do not ask for changes outside the files the task touches unless a '
        .'defect forces it; then name that defect. If you find no blocking finding, approve.';

    /**
     * How a reviewer in a copy approves (docs/reference/tasks.md#the-reviewers-pull-request-text): it sends the pull request text through
     * its copy key. Orbit opens the pull request once the group is done; the copy holds no Git token.
     */
    public const string PrTextRule = 'If you approve this commit, write its pull request: a title of one line, and a body in Markdown in a file such as '
        .'pr-body.md. Send them before this turn ends: . ~/.config/orbit/model.env && curl -sS --fail-with-body -H "Authorization: Bearer $ORBIT_COPY_KEY" '
        .'--form-string "title=$TITLE" -F \'body=<pr-body.md\' "$ORBIT_COPY_API/pull-request" (set TITLE to the title first). A second send replaces the text. '
        .'Orbit opens the pull request with it once the work is done; when the pull request is open already, the next publish of this check updates its title and body with it. If you do not approve, send nothing.';

    public function __construct(private TaskOperations $operations, private TaskCheckedCandidate $candidates) {}

    public function start(Task $group, TaskTurnData $data, string $caller, ?string $request): Operation
    {
        return $this->operations->start($group, OperationKinds::TasksCopyTurn, $data->toArray(), static function (Task $group): void {},
            $caller, $request, fn (Task $group): array => $this->capture($group, $data));
    }

    /**
     * Runs inside the admission's transaction. It first takes the group's row lock, which a subtask's completion takes too
     * (UpdateTaskAction), so "a subtask completes only after its turns ended" holds on any database, not only through SQLite's
     * BEGIN IMMEDIATE: a completion and a turn admission on one group never interleave.
     *
     * @return array<string,mixed>
     */
    public function capture(Task $group, TaskTurnData $data): array
    {
        Task::topLevel()->whereKey($group->id)->lockForUpdate()->firstOrFail();
        $group->load('taskable', 'project');
        $workspace = TaskInstanceWorkspace::forGroup($group)
            ?? throw new ResourceOperationException('tasks.no_workspace', 'The group requires its task copy.', 409);
        $workspace->requireReady();
        $task = $this->operations->requireCurrentSubtask($group, $data->subtask);
        $role = $data->threadRole();
        $instance = $workspace->instance;
        $runtime = 'instance:'.$instance->id;
        // The role's thread in this copy: the implementer's of the current subtask, or the group's reviewer thread. A turn continues it
        // unless the caller asks for a new one; `--thread=ID` may only name it.
        $recorded = $role === TaskThreadRole::Implementer ? $task->implementer_agent_thread_id : $group->reviewer_agent_thread_id;
        $current = $recorded === null ? null : AgentThread::query()->where('task_group_id', $group->id)->where('role', $role->value)
            ->where('driver', self::Driver)->where('runtime_key', $runtime)->find($recorded);
        if ($data->thread !== null && $data->thread !== 'new' && (int) $data->thread !== $current?->id) {
            throw new ResourceOperationException('tasks.thread_role_mismatch', 'The thread must be the role\'s thread that this turn continues in the task copy.', 422);
        }
        $thread = $data->thread === 'new' ? null : $current;
        // An earlier turn that may still run is settled first, by this turn's own phase in the copy (docs/cli/task/copy-turn.mmd).
        // A reviewer's turn names the task branch its checkout goes back to, should the copy still hold its detach.
        $settle = array_map(static fn (Operation $turn): array => ['operation_id' => $turn->id,
            'title' => ValidatedData::string(ValidatedData::object($turn->input['turn'])['title']),
            'branch' => ($turn->input['candidate'] ?? null) === null ? null : ValidatedData::string($turn->input['branch'])], self::unsettled($group));
        $candidate = null;
        if ($role === TaskThreadRole::Reviewer) {
            $check = TaskCheck::query()->where('task_id', $task->id)->find($data->check);
            if (! $check instanceof TaskCheck || ! $this->candidates->eligible($group, $check)) {
                throw new ResourceOperationException('tasks.commit_not_checked', 'Review requires the current subtask checked candidate.', 422);
            }
            $candidate = (string) $check->commit_sha;
        }
        $provider = null;
        $options = [];
        if ($thread === null) {
            $agent = TaskCopyAgent::forNewThread($group, $role);
            $provider = $agent['instance'];
            $options = $agent['options'];
            $thread = AgentThread::query()->create([
                'task_group_id' => $group->id, 'task_id' => $task->id, 'node_id' => $instance->node_id,
                'driver' => self::Driver, 'runtime_key' => $runtime, 'external_id' => (string) Str::uuid(),
                'role' => $role->value, 'model' => $agent['model'], 'effort' => $agent['effort'], 'provider_instance' => $provider,
            ]);
        }
        // A turn starts the subtask: `todo` moves to `running` under the group's lock. Only the caller completes it (docs/reference/tasks.md#subtask-status-in-a-task-copy).
        if ($task->status === TaskStatus::Todo) {
            $task->update(['status' => TaskStatus::Running]);
        }
        ($role === TaskThreadRole::Implementer ? $task : $group)->update([$role->value.'_agent_thread_id' => $thread->id]);
        app(TaskBroadcasts::class)->threadChanged($thread->id);
        $target = $workspace->intent();
        // The phase checks the candidate out for the reviewer and the task branch out again after it (docs/cli/task/copy-turn.mmd).
        // A turn that continues a thread whose last turn asked answers it: the questions come first, in the agent's own words.
        $prompt = $provider === null && $thread->questions !== null ? self::asked($thread->questions)."\n\nAnswer:\n".$data->prompt : $data->prompt;
        $prompt = $candidate === null ? $prompt."\n\n".self::AskRule : $prompt."\n\nThe checked commit under review: {$candidate}. The checkout holds it, "
            .'detached. Orbit checks the task branch out again after this turn and discards every change in the checkout.'."\n\n".self::ReviewRule."\n\n".self::PrTextRule;

        return [
            ...$data->toArray(), 'group_id' => $group->id, 'instance_id' => $instance->id, 'node_id' => $instance->node_id,
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($instance->node),
            'runtime' => $target['runtime'], 'branch' => $target['branch'], 'thread_id' => $thread->id, 'candidate' => $candidate,
            'turn' => ['t3' => $instance->runtime()->firstOrFail()->server()->program(), 'node' => InstanceContainer::Node, 'thread' => $provider === null ? 'continue' : 'new', 'provider' => $provider, 'model' => $thread->model,
                // A new thread's model options: T3 runs its every turn with them (InstanceContainer::providerOptions).
                'options' => $options, 'candidate' => $candidate, 'settle' => $settle,
                'answered' => $provider === null ? array_values(array_unique(array_map(
                    static fn (array $question): string => ValidatedData::string($question['request_id']), $thread->questions ?? []))) : [],
                'seconds' => self::TurnSeconds, 'title' => 'Orbit task '.$group->id.' thread '.$thread->id,
                'prompt' => TaskTurnPrompt::compose($task, $prompt, now())],
        ];
    }

    /**
     * Refuses a check, a publish or a proof of the copy while an earlier turn is not settled: none of them can read T3 in the copy. It
     * waits for the next turn, which stops the earlier run and settles it in its own phase.
     */
    public static function refuseUnended(Task $group): void
    {
        $unended = self::unsettled($group)[0] ?? null;
        if ($unended instanceof Operation) {
            throw new ResourceOperationException('tasks.copy_turn_unended', 'An earlier turn may still run in the task copy or left its checkout unrestored. '
                .'A check, publish or proof waits for the next task:turn, which stops that run and settles it. operation:cancel ends a running turn, '
                .'and the next turn stops its run.', 409, details: ['operation_id' => $unended->id]);
        }
    }

    /**
     * Refuses to complete or reopen a subtask of a task copy's group while a copy turn of the group has not ended its Operation. A turn is
     * admitted only on the current subtask, so a subtask completed after this check was completed after every turn on it ended: the done
     * rule (docs/reference/tasks.md#when-the-work-is-done) needs no clock for "reviewed before completed".
     */
    public static function refuseSubtaskMoveWhileRunning(Task $group): void
    {
        // A group without a task copy has no copy turns, so the query alone is the rule.
        $running = Operation::query()->where('target_type', OperationKinds::TaskGroupTarget)->where('target_id', $group->id)
            ->where('kind', OperationKinds::TasksCopyTurn)->whereNotIn('status', OperationStatus::terminalValues())->orderBy('id')->first();
        if ($running instanceof Operation) {
            throw new ResourceOperationException('tasks.copy_turn_unended', 'A turn still runs in the task copy; complete or reopen the subtask once it has ended.', 409,
                details: ['operation_id' => $running->id]);
        }
    }

    /**
     * The group's copy turns that started a run and are not settled. One rule settles a turn: T3 shows its thread with no active run,
     * and a reviewer's detach is undone (docs/cli/task/copy-turn.mmd). The turn's own result records that as `ended`; a later turn's phase that
     * found it so names it in its result's `settled`. A turn that asked and whose run Orbit stopped ended.
     *
     * @return list<Operation>
     */
    public static function unsettled(Task $group): array
    {
        $turns = Operation::query()->where('target_type', OperationKinds::TaskGroupTarget)->where('target_id', $group->id)
            ->where('kind', OperationKinds::TasksCopyTurn)->whereIn('status', OperationStatus::terminalValues())->orderBy('id')->get();
        $settled = $turns->flatMap(static fn (Operation $turn): array => ValidatedData::integerList($turn->result['settled'] ?? []))->all();

        return array_values($turns->filter(static fn (Operation $turn): bool => ($turn->state['swamp_run_id'] ?? null) !== null
            && ($turn->result['ended'] ?? null) !== true && ! in_array($turn->id, $settled, true))->all());
    }

    /**
     * The questions a thread's last turn asked, each with its header and options, as the next turn quotes them.
     *
     * @param  list<array<string, mixed>>  $questions
     */
    public static function asked(array $questions): string
    {
        $lines = ['You asked in your last turn:'];
        foreach ($questions as $question) {
            $header = ValidatedData::string($question['header'] ?? '');
            $lines[] = ($header === '' ? '' : "[{$header}] ").ValidatedData::string($question['question']);
            foreach (is_array($question['options'] ?? null) ? $question['options'] : [] as $option) {
                $option = ValidatedData::object($option);
                $description = ValidatedData::string($option['description'] ?? '');
                $lines[] = '- '.ValidatedData::string($option['label']).($description === '' ? '' : ': '.$description);
            }
        }

        return implode("\n", $lines);
    }
}
