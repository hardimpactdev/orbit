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
final readonly class TaskTurn
{
    /**
     * The driver of a copy turn's thread. Orbit stops a copy's run only inside a turn's phase, through the stock T3 server in the copy:
     * a run that asks, a run cut by the turn's bound, and the run of an earlier turn that is over in Orbit. The registry holds no driver
     * for it.
     */
    public const string Driver = 't3';

    /** How long the agent's turn may run before Swamp ends its wait; the bound of a task VM turn. */
    public const int TurnSeconds = 3600;

    /**
     * How a reviewer in a copy approves (docs/reference/tasks.md#the-reviewers-pull-request-text): it sends the pull request text through
     * its copy key. The reviewer's prompt holds it after its review rules (TaskTurnPrompt::reviewer). Orbit opens the pull request once
     * the group is done; the copy holds no Git token.
     */
    public const string PrTextRule = 'If you approve this commit, write its pull request: a title of one line, and a body in Markdown in a file such as '
        .'pr-body.md. Send them before this turn ends: . ~/.config/orbit/model.env && curl -sS --fail-with-body -H "Authorization: Bearer $ORBIT_COPY_KEY" '
        .'--form-string "title=$TITLE" -F \'body=<pr-body.md\' "$ORBIT_COPY_API/pull-request" (set TITLE to the title first). A second send replaces the text. '
        .'Orbit opens the pull request with it once the work is done; when the pull request is open already, the next publish of this check updates its title and body with it. If you do not approve, send nothing.';

    /**
     * How a reviewer in a gated group's copy posts its review receipt (docs/reference/tasks.md#the-review-receipt): its verdict and the
     * category of each blocking finding, with each category's meaning, through its copy key. The reviewer's prompt holds it after its
     * review rules (TaskTurnPrompt::reviewer).
     */
    public static function reviewReceiptRule(): string
    {
        $categories = implode("\n", array_map(static fn (TaskFindingCategory $category): string => "- {$category->value}: {$category->meaning()}",
            TaskFindingCategory::cases()));

        return 'Before this turn ends, post your review receipt: your verdict and the category of each blocking finding you reported, '
            .'one entry per finding, none for approve and at least one for changes. . ~/.config/orbit/model.env && curl -sS --fail-with-body '
            .'-H "Authorization: Bearer $ORBIT_COPY_KEY" -H "Content-Type: application/json" -d \'{"verdict": "changes", "findings": ["regression", "docs"]}\' '
            .'"$ORBIT_COPY_API/handoff" (set the verdict, approve or changes, and the categories first; approve posts "findings": []). '
            ."A second post replaces the first. Without a receipt the review does not count. The categories:\n{$categories}";
    }

    /**
     * How a copy thread's external_id came to be (docs/reference/development-containers.md#admission). Capture sets a UUID and marks it
     * `admitted`: T3 may not hold it yet. A turn that reports that same ID back makes it `confirmed`. A reported ID that replaced the row's
     * ID, or any report on a row made before the mark, makes it `overwritten`.
     */
    public const string Admitted = 'admitted';

    public const string Confirmed = 'confirmed';

    public const string Overwritten = 'overwritten';

    /** A row made before the mark whose ID no turn reported: it still holds the UUID that capture set, which T3 never received. */
    public const string Provisional = 'provisional';

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
        $settle = array_map(self::earlier(...), self::unsettled($group));
        $candidate = null;
        $review = null;
        if ($role === TaskThreadRole::Implementer) {
            // An accepted delivery waits for its review before the implementer goes on (docs/reference/tasks.md#the-review-gate).
            TaskHandoffs::requireReview($group, $task);
        }
        if ($role === TaskThreadRole::Reviewer) {
            $check = TaskCheck::query()->where('task_id', $task->id)->find($data->check);
            // The delivery gate comes first (docs/reference/tasks.md#handoffs): only an accepted delivery for this commit is reviewed.
            if ($check instanceof TaskCheck) {
                TaskHandoffs::requireDelivery($group, $task, $check);
            }
            $owner = $check instanceof TaskCheck ? $this->candidates->owner($group, $check) : null;
            if (! $check instanceof TaskCheck || ! $owner instanceof Operation || ! $this->candidates->eligible($group, $check)) {
                throw new ResourceOperationException('tasks.commit_not_checked', 'Review requires the current subtask checked candidate.', 422);
            }
            $candidate = (string) $check->commit_sha;
            $review = TaskTurnReview::of($group, $task, $check, $owner);
        }
        $provider = null;
        $options = [];
        $copyRuntime = $instance->runtime()->firstOrFail();
        if ($thread === null) {
            $agent = TaskAgent::forNewThread($group, $role, $copyRuntime);
            $provider = $agent['instance'];
            $options = $agent['options'];
            $thread = AgentThread::query()->create([
                'task_group_id' => $group->id, 'task_id' => $task->id, 'node_id' => $instance->node_id,
                'driver' => self::Driver, 'runtime_key' => $runtime, 'external_id' => (string) Str::uuid(), 'external_id_admission' => self::Admitted,
                'role' => $role->value, 'model' => $agent['model'], 'effort' => $agent['effort'], 'provider_instance' => $provider,
            ]);
        }
        // A turn starts the subtask: `todo` moves to `running` under the group's lock, and its first turn stamps its start, which its
        // duration counts from (docs/reference/tasks.md#metrics). Only the caller completes it (docs/reference/tasks.md#subtask-status-in-a-task-copy).
        if ($task->status === TaskStatus::Todo) {
            $task->update(['status' => TaskStatus::Running, 'started_at' => $task->started_at ?? now()]);
        }
        ($role === TaskThreadRole::Implementer ? $task : $group)->update([$role->value.'_agent_thread_id' => $thread->id]);
        app(TaskBroadcasts::class)->threadChanged($thread->id);
        $target = $workspace->intent();
        // The phase checks the candidate out for the reviewer and the task branch out again after it (docs/cli/task/copy-turn.mmd).
        // A turn that continues a thread whose last turn asked answers it: the questions come first, in the agent's own words.
        $prompt = $provider === null && $thread->questions !== null ? self::asked($thread->questions)."\n\nAnswer:\n".$data->prompt : $data->prompt;
        // Each role gets its own fixed text around the caller's prompt; a reviewer's also names its check, its round and the handoff note.
        $prompt = $review === null ? TaskTurnPrompt::implementer($task, $group->project->taskCheckCommand(), $prompt, now())
            : TaskTurnPrompt::reviewer($task, $review, $prompt, now());

        return [
            ...$data->toArray(), 'group_id' => $group->id, 'instance_id' => $instance->id, 'node_id' => $instance->node_id,
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($instance->node),
            'runtime' => $target['runtime'], 'branch' => $target['branch'], 'thread_id' => $thread->id, 'candidate' => $candidate,
            'turn' => ['t3' => $copyRuntime->server()->program(), 'node' => InstanceContainer::Node, 'thread' => $provider === null ? 'continue' : 'new', 'provider' => $provider, 'model' => $thread->model,
                // A new thread's model options: T3 runs its every turn with them (InstanceContainer::providerOptions).
                'options' => $options, 'candidate' => $candidate, 'settle' => $settle,
                // The thread as T3 is to know it, and the turn's own message ID, fixed before any send (docs/reference/development-containers.md#stable-ids).
                't3_thread_id' => $thread->external_id, 'admission' => self::admission($thread), 'message_id' => (string) Str::uuid(),
                // The answer cancels only stored requests of the run that owns them; a stored question without one is not answerable.
                ...self::answerable($provider === null ? $thread->questions ?? [] : []),
                'seconds' => self::TurnSeconds, 'title' => 'Orbit task '.$group->id.' thread '.$thread->id,
                'prompt' => $prompt],
        ];
    }

    /**
     * What a turn passes of an earlier turn that may still run (docs/reference/development-containers.md#earlier-turns): its thread, its
     * frozen message ID and the SHA-256 digest of its frozen message body, never the body, the run it recorded, whether it was sent, the
     * requests it answered and the run that owned them. A turn with no result counts as possibly sent; a result from before `sent` names
     * it as `launched`. A turn that reported no thread and froze an admitted ID passes that ID marked `admitted`: the RPC target
     * creates the thread with it, but no T3 was seen to receive it, so the CLI phase finds that turn's thread by its title. A provisional
     * ID names none.
     *
     * @return array<string, mixed>
     */
    private static function earlier(Operation $turn): array
    {
        $intent = ValidatedData::object($turn->input['turn']);
        $result = $turn->result ?? [];
        $reported = self::nonEmpty($result['t3_thread_id'] ?? null);
        $admitted = $reported === null && array_key_exists('t3_thread_id', $intent) && ($intent['admission'] ?? null) === self::Admitted
            ? self::nonEmpty($intent['t3_thread_id']) : null;

        return ['operation_id' => $turn->id, 'title' => ValidatedData::string($intent['title']),
            'branch' => ($turn->input['candidate'] ?? null) === null ? null : ValidatedData::string($turn->input['branch']),
            // The thread that turn reported, else the one it froze while T3 held it or admitted, else, for a turn admitted before the
            // freeze, its row's while T3 holds it: a cancelled turn records no report on the row.
            't3_thread_id' => $reported ?? $admitted ?? (array_key_exists('t3_thread_id', $intent)
                ? (self::held($intent['admission'] ?? null) ? self::nonEmpty($intent['t3_thread_id']) : null)
                : self::heldId(AgentThread::query()->find(ValidatedData::integer($turn->input['thread_id'])))),
            'admitted' => $admitted !== null,
            'message_id' => is_string($intent['message_id'] ?? null) ? $intent['message_id'] : null,
            'digest' => hash('sha256', ValidatedData::string($intent['prompt'])),
            'run_id' => is_string($result['run_id'] ?? null) ? $result['run_id'] : null,
            'sent' => ($result['sent'] ?? $result['launched'] ?? true) !== false,
            'answered' => array_values(array_map(ValidatedData::string(...), is_array($intent['answered'] ?? null) ? $intent['answered'] : [])),
            'answered_run' => self::nonEmpty($intent['answered_run'] ?? null)];
    }

    /** Whether a thread ID of this standing is one T3 holds: a turn reported it. */
    private static function held(mixed $admission): bool
    {
        return in_array($admission, [self::Confirmed, self::Overwritten], true);
    }

    private static function heldId(?AgentThread $thread): ?string
    {
        return $thread instanceof AgentThread && self::held(self::admission($thread)) ? self::nonEmpty($thread->external_id) : null;
    }

    /**
     * The stored requests a continued turn answers, and the one run that owns them all: the helper cancels only requests of that run
     * (docs/reference/development-containers.md#questions). Questions without an owner, or of several runs, are answered by none.
     *
     * @param  list<array<string, mixed>>  $questions
     * @return array{answered: list<string>, answered_run: string|null}
     */
    private static function answerable(array $questions): array
    {
        $owners = array_values(array_unique(array_map(static fn (array $question): string => is_string($question['run_id'] ?? null) ? $question['run_id'] : '', $questions)));
        if (count($owners) !== 1 || $owners[0] === '') {
            return ['answered' => [], 'answered_run' => null];
        }

        return ['answered' => array_values(array_unique(array_map(static fn (array $question): string => ValidatedData::string($question['request_id']), $questions))),
            'answered_run' => $owners[0]];
    }

    private static function nonEmpty(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * How the thread's external_id came to be: its mark, or for a row made before the mark, `provisional` while no copy turn of it
     * reported a T3 thread ID and `overwritten` once one did.
     */
    public static function admission(AgentThread $thread): string
    {
        if ($thread->external_id_admission !== null) {
            return $thread->external_id_admission;
        }
        $reported = Operation::query()->where('target_type', OperationKinds::TaskGroupTarget)->where('target_id', $thread->task_group_id)
            ->where('kind', OperationKinds::TasksCopyTurn)->get()
            ->contains(static fn (Operation $turn): bool => ($turn->input['thread_id'] ?? null) === $thread->id
                && is_string($turn->result['t3_thread_id'] ?? null) && $turn->result['t3_thread_id'] !== '');

        return $reported ? self::Overwritten : self::Provisional;
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
