<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Models\AgentThread;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Records the native result and the board's existing thread observation. */
final readonly class TaskTurnProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return ['intent' => array_diff_key($operation->input, ['execution_secrets' => true]),
            'execution_secrets' => $operation->input['execution_secrets']];
    }

    /**
     * Records what a finished turn reports on the thread it ran on: its token metrics and its final state.
     *
     * @param  array<string,mixed>  $result
     */
    public static function recordThread(int $group, int $thread, array $result): void
    {
        AgentThread::query()->where('task_group_id', $group)->findOrFail($thread)->update([
            ...array_intersect_key(ValidatedData::object($result['metrics'] ?? []), array_flip(['tokens', 'input_tokens', 'cached_input_tokens', 'output_tokens', 'model_calls', 'peak_context_tokens'])),
            'state' => $result['state'] ?? 'failed', 'error' => $result['error'] ?? null, 'observed_at' => now(),
        ]);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $terminal = $operation->status->terminal();
            $native = ValidatedData::object($data['result'] ?? []);
            $result = isset($native['turn'])
                ? ValidatedData::object(json_decode(ValidatedData::string($native['turn']), true, flags: JSON_THROW_ON_ERROR))
                : [];
            if (in_array($data['status'] ?? null, ['failed', 'interrupted'], true) && isset($result['error_code'])) {
                $data['error_code'] = $result['error_code'];
            }
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if (! $terminal && $projected->status->terminal()) {
                self::recordThread($operation->target_id, ValidatedData::integer($operation->input['thread_id']), $result);
            }

            app(TaskMaintenanceContinuation::class)->apply($projected);

            return $projected;
        });
    }
}
