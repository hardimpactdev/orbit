<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Tasks\ArchiveAgentTranscriptsAction;
use App\Domain\Instances\InstanceThreadContributions;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationStartRefusal;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * Records a copy turn: the thread's state and error, and its transcript in the Gateway's archive,
 * which outlives the copy. A turn that asked keeps its questions on the thread until the thread's next turn. The result names the
 * provider instance, the model and the effort the thread started with, the agent thread's id and the agent's last reply; the transcript
 * stays out of it. T3's CLI reports no token use, so the metrics stay null.
 */
final readonly class TaskCopyTurnProjection implements DirectOperationProjection, DirectOperationStartRefusal
{
    /** The most characters of the agent's reply that a turn's result holds. */
    public const int ReplyLimit = 16384;

    /**
     * What a failed turn's error says, by its code (docs/cli/task/turn.mdx#errors). The worker's own error text is never kept: T3's or a
     * provider's words may quote the prompt, also from an older worker. No sentence says the run ended: Held adds whether the turn holds the group.
     */
    /** What a failed turn's error adds when its result says the turn has not ended (`ended: false`). */
    public const string Held = ' Its run may still go on: the group holds until the next turn settles this turn.';

    public const array Errors = [
        'tasks.copy_turn_unended' => 'An earlier turn may still run in the copy, or a reviewer left its checkout unclean; nothing was launched.',
        'tasks.copy_turn_model_unoffered' => 'The copy does not offer the thread\'s model; nothing was launched.',
        'tasks.copy_turn_model_unverified' => 'Orbit could not verify the thread\'s model in the copy; nothing was launched.',
        'tasks.copy_thread_missing' => 'T3 in the copy holds no single thread to continue; nothing was launched.',
        'tasks.copy_tree_dirty' => 'The checkout had changes or no branch, so the reviewer could not be put on the candidate.',
        'tasks.copy_review_unrestored' => 'The checkout was not restored after the reviewer\'s turn.',
        'tasks.copy_question_unread' => 'The run asked, but Orbit could not read its question with the run that owns it, so it did not stop the run.',
        'tasks.copy_question_unstopped' => 'Orbit stored the question but could not stop the run that asked.',
        'tasks.copy_question_unresolved' => 'Orbit could not confirm that the stored questions are resolved in T3; nothing was launched.',
        'tasks.copy_question_answered' => 'The prompt quotes questions that an earlier answer already used; send the prompt again.',
        'tasks.turn_timeout' => 'The turn ran longer than its bound.',
        'tasks.turn_failed' => 'T3 did not report the run as done or asking for input.',
        'tasks.copy_turn_failed' => 'T3 did not report the turn\'s end.',
    ];

    public function input(Operation $operation): array
    {
        return ['intent' => $operation->input, 'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $terminal = $operation->status->terminal();
            $captured = ValidatedData::object($data['result'] ?? [])['turn'] ?? null;
            $result = is_string($captured) ? json_decode($captured, true, flags: JSON_THROW_ON_ERROR) : $captured;
            $result = is_array($result) ? ValidatedData::object($result) : [];
            if ($result !== []) {
                // The agent the thread started with, as the admission resolved and recorded it (TaskCopyAgent).
                $thread = AgentThread::query()->findOrFail(ValidatedData::integer($operation->input['thread_id']));
                $result['provider_instance'] = $thread->provider_instance;
                $result['model'] = $thread->model;
                $result['effort'] = $thread->effort;
                $result['agent_id'] = $thread->id;
                $result['reply'] = self::reply($operation->target_id, $thread->id, $result['transcript'] ?? null);
                // A question that this turn's run does not own, without an owner or another run's, could never be cancelled exactly: it is
                // not stored, the turn did not read it, and
                // its run may still wait, so the turn holds the group until the next turn settles it. An unrestored reviewer checkout outranks
                // the unread question: its code stays.
                if (array_filter(is_array($result['questions'] ?? null) ? $result['questions'] : [],
                    static fn (mixed $question): bool => ! is_array($question) || ! is_string($question['run_id'] ?? null) || $question['run_id'] === ''
                        || $question['run_id'] !== ($result['run_id'] ?? null)) !== []) {
                    $result = [...$result, 'questions' => [], 'ended' => false, 'error' => '',
                        'error_code' => ($result['error_code'] ?? null) === 'tasks.copy_review_unrestored' ? 'tasks.copy_review_unrestored' : 'tasks.copy_question_unread'];
                    $data['status'] = ($data['status'] ?? null) === 'succeeded' ? 'failed' : ($data['status'] ?? null);
                }
                $result = [...array_diff_key($result, ['diagnostic' => true]), ...self::error($result)];
            }
            if (in_array($data['status'] ?? null, ['failed', 'interrupted'], true) && isset($result['error_code'])) {
                $data['error_code'] = $result['error_code'];
            }
            $projected = app(DirectOperation::class)->project($operation, $data, array_diff_key($result, ['transcript' => true]));
            if (! $terminal && $projected->status->terminal() && ($data['status'] ?? null) !== 'cancelled') {
                self::record($projected, $result);
            }

            return $projected;
        });
    }

    /**
     * The text of the transcript's last entry that the agent wrote, labelled `assistant`, cut at the end past ReplyLimit with a marker that names the
     * rest and where to read it; null without one, also when Swamp's truncation notice, labelled `system`, is the only other entry (docs/cli/task/turn.mdx#a-task-that-runs-in-a-copy).
     */
    private static function reply(int $group, int $agent, mixed $transcript): ?string
    {
        $text = null;
        foreach (is_array($transcript) ? $transcript : [] as $entry) {
            if (is_array($entry) && ($entry['label'] ?? null) === 'assistant' && is_string($entry['text'] ?? null)) {
                $text = $entry['text'];
            }
        }
        if ($text === null || mb_strlen($text) <= self::ReplyLimit) {
            return $text;
        }
        $rest = mb_strlen($text) - self::ReplyLimit;

        return mb_substr($text, 0, self::ReplyLimit)." [cut: {$rest} more characters; read task:agent:show {$group} {$agent}]";
    }

    /** Swamp refused to start the run: the thread that the admission created ran no turn. */
    public function refused(Operation $operation): void
    {
        AgentThread::query()->where('task_group_id', $operation->target_id)->whereKey(ValidatedData::integer($operation->input['thread_id']))
            ->whereNull('state')->update(['state' => AgentThreadState::Failed, 'error' => 'Swamp refused to start the turn.', 'observed_at' => now()]);
    }

    /** @param array<string,mixed> $result */
    private static function record(Operation $operation, array $result): void
    {
        $thread = ValidatedData::integer($operation->input['thread_id']);
        $state = AgentThreadState::tryFrom(is_string($result['state'] ?? null) ? $result['state'] : '');
        $result = [...$result, 'state' => in_array($state, [AgentThreadState::Done, AgentThreadState::AskingForInput], true) ? $state->value : AgentThreadState::Failed->value];
        if (is_string($result['t3_thread_id'] ?? null) && $result['t3_thread_id'] !== '') {
            // Only the admitted ID that T3 reported back is confirmed; a reported ID that replaced it, or a report on a row from before the
            // mark, leaves an ID of no proven provenance (docs/reference/development-containers.md#admission).
            $record = AgentThread::query()->where('task_group_id', $operation->target_id)->findOrFail($thread);
            $record->update(['external_id' => $result['t3_thread_id'], 'external_id_admission' => $record->external_id === $result['t3_thread_id']
                && in_array($record->external_id_admission, [TaskCopyTurn::Admitted, TaskCopyTurn::Confirmed], true) ? TaskCopyTurn::Confirmed : TaskCopyTurn::Overwritten]);
            // The extensions record the copy's new thread under the title the turn gave it (docs/reference/extensions.md#plug-in-points).
            $instance = Instance::query()->find(ValidatedData::integer($operation->input['instance_id']));
            $title = ValidatedData::object($operation->input['turn'])['title'];
            if ($instance instanceof Instance && is_string($title)) {
                $threadId = $result['t3_thread_id'];
                DB::afterCommit(static fn () => app(InstanceThreadContributions::class)->recordThread($instance, $threadId, $title));
            }
        }
        self::recordThread($operation->target_id, $thread, $result);
        // An earlier answer turn's confirmed run used its answer: a thread whose stored request IDs are exactly the spent ones asks nothing
        // any more (docs/reference/development-containers.md#earlier-turns).
        $spent = self::requestSet(array_map(ValidatedData::string(...), is_array($result['spent'] ?? null) ? array_values($result['spent']) : []));
        if ($spent !== []) {
            AgentThread::query()->where('task_group_id', $operation->target_id)->whereNotNull('questions')->get()
                ->filter(static fn (AgentThread $asked): bool => self::requestSet(array_map(
                    static fn (array $question): string => ValidatedData::string($question['request_id']), $asked->questions ?? [])) === $spent)
                ->each(static fn (AgentThread $asked): bool => $asked->update(['questions' => null]));
        }
        // Only a turn whose run T3 confirmed replaces what the thread asked: one that launched nothing, or was only possibly sent, leaves
        // the question open.
        if (($result['launched'] ?? null) === true) {
            $questions = $result['state'] === AgentThreadState::AskingForInput->value && is_array($result['questions'] ?? null) && $result['questions'] !== []
                ? array_values(array_map(ValidatedData::object(...), $result['questions'])) : null;
            AgentThread::query()->where('task_group_id', $operation->target_id)->whereKey($thread)
                ->update(['questions' => $questions === null ? null : json_encode($questions, JSON_THROW_ON_ERROR)]);
        }
    }

    /**
     * The error a turn's result keeps: the fixed sentence of its code, and Held when the turn has not ended, never the worker's text or
     * anything parsed from it. A code outside Errors, or an error with no code, is `tasks.copy_turn_failed`.
     *
     * @param  array<string, mixed>  $result
     * @return array{error: string|null, error_code?: string}
     */
    private static function error(array $result): array
    {
        if (($result['error'] ?? null) === null && ! isset($result['error_code'])) {
            return ['error' => null];
        }
        $code = is_string($result['error_code'] ?? null) && array_key_exists($result['error_code'], self::Errors) ? $result['error_code'] : 'tasks.copy_turn_failed';

        return ['error' => self::Errors[$code].(($result['ended'] ?? null) === true ? '' : self::Held), 'error_code' => $code];
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private static function requestSet(array $ids): array
    {
        // As strings: a default sort compares numeric strings as numbers, so "01" and "1" would not order alike.
        $ids = array_values(array_unique($ids, SORT_STRING));
        sort($ids, SORT_STRING);

        return $ids;
    }

    /**
     * Records the thread's final state and error, and archives its transcript. T3 reports no token use, so no metric is recorded.
     *
     * @param  array<string,mixed>  $result
     */
    private static function recordThread(int $group, int $thread, array $result): void
    {
        $record = AgentThread::query()->where('task_group_id', $group)->lockForUpdate()->findOrFail($thread);
        $record->update(['state' => $result['state'] ?? 'failed', 'error' => $result['error'] ?? null, 'observed_at' => now(), 'observation_error' => null]);
        if (isset($result['transcript'])) {
            // A user message keeps only its ID, author and time, also from a worker that still sends its text: the prompt stays out of the archive.
            app(ArchiveAgentTranscriptsAction::class)->record($record, array_map(static function (mixed $entry): array {
                $entry = ValidatedData::object($entry);

                return ($entry['label'] ?? null) === 'user' ? [...$entry, 'text' => ''] : $entry;
            }, is_array($result['transcript']) ? array_values($result['transcript']) : []));
        }
    }
}
