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
        // An earlier answer turn's confirmed run used its answer: a thread whose every stored request is spent asks nothing any more
        // (docs/reference/development-containers.md#earlier-turns).
        $spent = array_map(ValidatedData::string(...), is_array($result['spent'] ?? null) ? array_values($result['spent']) : []);
        if ($spent !== []) {
            AgentThread::query()->where('task_group_id', $operation->target_id)->whereNotNull('questions')->get()
                ->filter(static fn (AgentThread $asked): bool => array_diff(array_map(static fn (array $question): string => ValidatedData::string($question['request_id']), $asked->questions ?? []), $spent) === [])
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
     * Records the thread's final state and error, and archives its transcript. T3 reports no token use, so no metric is recorded.
     *
     * @param  array<string,mixed>  $result
     */
    private static function recordThread(int $group, int $thread, array $result): void
    {
        $record = AgentThread::query()->where('task_group_id', $group)->lockForUpdate()->findOrFail($thread);
        $record->update(['state' => $result['state'] ?? 'failed', 'error' => $result['error'] ?? null, 'observed_at' => now(), 'observation_error' => null]);
        if (isset($result['transcript'])) {
            app(ArchiveAgentTranscriptsAction::class)->record($record, array_map(ValidatedData::object(...), is_array($result['transcript']) ? array_values($result['transcript']) : []));
        }
    }
}
