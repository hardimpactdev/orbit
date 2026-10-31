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

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $terminal = $operation->status->terminal();
            $result = ValidatedData::object($data['result'] ?? []);
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if (! $terminal && $projected->status->terminal()) {
                $thread = AgentThread::query()->where('task_group_id', $operation->target_id)->findOrFail(ValidatedData::integer($operation->input['thread_id']));
                $thread->update([
                    ...array_intersect_key(ValidatedData::object($result['metrics'] ?? []), array_flip(['tokens', 'input_tokens', 'cached_input_tokens', 'output_tokens', 'model_calls', 'peak_context_tokens'])),
                    'state' => $result['state'] ?? 'failed', 'error' => $result['error'] ?? null, 'observed_at' => now(),
                ]);
            }

            return $projected;
        });
    }
}
