<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Projects the native check metadata onto the existing board. No execution or evidence judgment. */
final readonly class TaskCheckProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return array_diff_key($operation->input, ['credentials' => true]);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $terminal = $operation->status->terminal();
            $result = ValidatedData::object($data['result'] ?? []);
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if (! $terminal && $projected->status->terminal()) {
                $check = TaskCheck::query()->findOrFail(ValidatedData::integer($operation->input['check_id']));
                $ci = is_array($result['ci'] ?? null) ? $result['ci'] : [];
                $check->update([
                    'status' => $projected->status->value === 'cancelled' ? 'cancelled' : ($result['outcome'] ?? 'failed'),
                    'head_before' => $result['head_before'] ?? '', 'tree_before' => $result['tree_before'] ?? '',
                    'head_after' => $result['head'] ?? null, 'tree_after' => $result['tree'] ?? null,
                    'exit_code' => $result['exit_code'] ?? null, 'changed_paths' => $result['changed_paths'] ?? [],
                    'output' => $result['output'] ?? '', 'failed_step' => $result['failed_step'] ?? null,
                    'deliverable_evidence' => $result['evidence'] ?? null, 'commit_sha' => $result['commit'] ?? null,
                    'pushed_at' => $ci['pushed_at'] ?? null, 'finished_at' => now(),
                ]);
                if (is_string($result['subtask_start_commit'] ?? null)) {
                    Task::query()->whereKey($operation->input['subtask'])->whereNull('subtask_start_commit')->update(['subtask_start_commit' => $result['subtask_start_commit']]);
                }
            }

            return $projected;
        });
    }
}
