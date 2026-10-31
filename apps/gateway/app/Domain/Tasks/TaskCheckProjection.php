<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
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
        $intent = array_diff_key($operation->input, ['execution_secrets' => true]);
        $serialize = static fn (array $step): array => [...$step, 'input_json' => json_encode($step, JSON_THROW_ON_ERROR)];
        $intent['setup_steps'] = array_map($serialize, array_map(ValidatedData::object(...), is_array($intent['setup']) ? $intent['setup'] : []));
        $intent['command_deliverables'] = array_map($serialize, array_values(array_filter(array_map(ValidatedData::object(...), is_array($intent['deliverables']) ? $intent['deliverables'] : []), static fn (array $item): bool => $item['type'] === 'command')));
        $intent['project_command_json'] = json_encode(['command' => $intent['command']], JSON_THROW_ON_ERROR);

        return ['intent' => $intent, 'execution_secrets' => $operation->input['execution_secrets']];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $terminal = $operation->status->terminal();
            if ($terminal) {
                return $operation;
            }
            $result = ValidatedData::object($data['result'] ?? []);
            $ci = is_array($result['ci'] ?? null) ? $result['ci'] : [];
            $pending = ($data['status'] ?? null) === 'succeeded' && ($result['pending'] ?? null) === true && in_array($ci['state'] ?? null, ['pending', 'missing'], true);
            if (! $terminal && $operation->cancel_requested_at !== null && ($pending || ($operation->result['pending'] ?? false) === true)) {
                $data['status'] = 'cancelled';
                $result['pending'] = false;
                $pending = false;
            }
            if ($pending) {
                $data['status'] = 'running';
                unset($data['finished_at']);
            }
            if (! $terminal && ($operation->result['pending'] ?? false) === true && ! $pending && ($data['status'] ?? null) !== 'cancelled') {
                $previous = $operation->result;
                foreach (['check_id', 'commit', 'head', 'tree'] as $field) {
                    if (($result[$field] ?? null) !== ($previous[$field] ?? null)) {
                        throw new ResourceOperationException('tasks.check_identity_mismatch', 'The scheduled result belongs to another checked candidate.', 409);
                    }
                }
                $ci = is_array($result['ci'] ?? null) ? $result['ci'] : [];
                if (($result['group_id'] ?? null) !== $operation->input['group_id']
                    || ($result['subtask'] ?? null) !== $operation->input['subtask']
                    || ($result['phase'] ?? null) !== $operation->input['phase']
                    || ($ci['repository'] ?? null) !== $operation->input['repository']
                    || ($result['check_id'] ?? null) !== $operation->input['check_id']
                    || ($ci['commit'] ?? null) !== ($previous['commit'] ?? null)
                    || ($ci['branch'] ?? null) !== $operation->input['branch']
                    || ($ci['check_name'] ?? null) !== $operation->input['check_name']) {
                    throw new ResourceOperationException('tasks.check_identity_mismatch', 'The scheduled result belongs to another admitted check.', 409);
                }
            }
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if (! $terminal && ($projected->status->terminal() || $pending)) {
                $check = TaskCheck::query()->findOrFail(ValidatedData::integer($operation->input['check_id']));
                $ci = is_array($result['ci'] ?? null) ? $result['ci'] : [];
                $check->update([
                    'status' => $pending ? 'running' : ($projected->status->value === 'cancelled' ? 'cancelled' : ($result['outcome'] ?? 'failed')),
                    'head_before' => $result['head_before'] ?? '', 'tree_before' => $result['tree_before'] ?? '',
                    'head_after' => $result['head'] ?? null, 'tree_after' => $result['tree'] ?? null,
                    'exit_code' => $result['exit_code'] ?? null, 'changed_paths' => $result['changed_paths'] ?? [],
                    'output' => $result['output'] ?? '', 'failed_step' => $result['failed_step'] ?? null,
                    'deliverable_evidence' => $result['evidence'] ?? null, 'commit_sha' => $result['commit'] ?? null,
                    'pushed_at' => $ci['pushed_at'] ?? null, 'finished_at' => $pending ? null : now(),
                ]);
                if (is_string($result['subtask_start_commit'] ?? null)) {
                    Task::query()->whereKey($operation->input['subtask'])->whereNull('subtask_start_commit')->update(['subtask_start_commit' => $result['subtask_start_commit']]);
                }
            }

            return $projected;
        });
    }
}
