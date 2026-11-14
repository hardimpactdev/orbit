<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationStartRefusal;
use App\Models\AgentThread;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * Records a copy turn where a task VM turn is recorded: the thread's state and error, and its transcript in the Gateway's archive,
 * which outlives the copy. T3's CLI reports no token use, so the metrics stay null.
 */
final readonly class TaskCopyTurnProjection implements DirectOperationProjection, DirectOperationStartRefusal
{
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
            AgentThread::query()->where('task_group_id', $operation->target_id)->whereKey($thread)->update(['external_id' => $result['t3_thread_id']]);
        }
        TaskTurnProjection::recordThread($operation->target_id, $thread, array_diff_key($result, ['metrics' => true]));
    }
}
