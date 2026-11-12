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
        return self::workflowInput($operation->input);
    }

    /**
     * The input of the check workflows from an admitted check intent. A review round passes the intent of its fix check through here.
     *
     * @param  array<string,mixed>  $input
     * @return array{intent: array<string,mixed>, execution_secrets: mixed}
     */
    public static function workflowInput(array $input): array
    {
        $intent = array_diff_key($input, ['execution_secrets' => true]);
        $intent['command_deliverables'] = array_values(array_filter(array_map(ValidatedData::object(...), is_array($intent['deliverables']) ? $intent['deliverables'] : []), static fn (array $item): bool => $item['type'] === 'command'));
        $intent['execution_references_json'] = json_encode($input['execution_secrets'] === [] ? (object) [] : $input['execution_secrets'], JSON_THROW_ON_ERROR);

        return ['intent' => $intent, 'execution_secrets' => $input['execution_secrets']];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            if ($operation->status->terminal()) {
                return $operation;
            }
            $result = $data['result'] = self::receipt(ValidatedData::object($data['result'] ?? []));
            if (in_array($data['status'] ?? null, ['failed', 'interrupted'], true) && is_string($result['error_code'] ?? null)) {
                $data['error_code'] = $result['error_code'];
            }
            $ci = is_array($result['ci'] ?? null) ? $result['ci'] : [];
            $pending = ($data['status'] ?? null) === 'succeeded' && ($result['pending'] ?? null) === true && in_array($ci['state'] ?? null, ['pending', 'missing'], true);
            if (($operation->result['pending'] ?? false) === true && ($data['status'] ?? null) !== 'cancelled') {
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
            if ($operation->cancel_requested_at !== null && ($pending || ($operation->result['pending'] ?? false) === true)) {
                $data['status'] = 'cancelled';
                $result['pending'] = false;
                $pending = false;
            }
            if ($pending) {
                $data['status'] = 'running';
                unset($data['finished_at']);
            }
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if ($projected->status->terminal() || $pending) {
                self::record(TaskCheck::query()->findOrFail(ValidatedData::integer($operation->input['check_id'])),
                    $pending ? 'running' : ($projected->status->value === 'cancelled' ? 'cancelled' : ($projected->status->value === 'failed' ? 'failed' : ($result['outcome'] ?? 'failed'))), $result, $pending);
                if (is_string($result['subtask_start_commit'] ?? null)) {
                    Task::query()->whereKey($operation->input['subtask'])->whereNull('subtask_start_commit')->update(['subtask_start_commit' => $result['subtask_start_commit']]);
                }
            }

            app(TaskMaintenanceContinuation::class)->apply($projected);

            return $projected;
        });
    }

    /** True while a check whose root succeeded stays running for hosted CI: a wait, not a missed settlement. */
    public static function awaitsHostedCi(Operation $operation): bool
    {
        return $operation->kind === 'tasks.check' && ! $operation->status->terminal() && ($operation->result['pending'] ?? false) === true;
    }

    /**
     * Writes what a check found onto its row. A review round calls this for the check of its fix, as the check operation does for its own.
     *
     * @param  array<string,mixed>  $result
     */
    public static function record(TaskCheck $check, mixed $status, array $result, bool $pending = false): void
    {
        $ci = is_array($result['ci'] ?? null) ? $result['ci'] : [];
        $check->update([
            'status' => $status,
            'head_before' => $result['head_before'] ?? '', 'tree_before' => $result['tree_before'] ?? '',
            'head_after' => $result['head'] ?? null, 'tree_after' => $result['tree'] ?? null,
            'exit_code' => $result['exit_code'] ?? null, 'changed_paths' => $result['changed_paths'] ?? [],
            'output' => $result['output'] ?? '', 'failed_step' => $result['failed_step'] ?? null,
            'deliverable_evidence' => $result['evidence'] ?? null, 'commit_sha' => $result['commit'] ?? null,
            'pushed_at' => $ci['pushed_at'] ?? null, 'finished_at' => $pending ? null : now(),
        ]);
    }

    /**
     * Root runs capture the push producer receipt. Scheduled observations supply their typed row result.
     *
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private static function receipt(array $result): array
    {
        $captured = $result['receipt'] ?? null;
        if (is_array($captured) && is_string($captured['result_json'] ?? null)) {
            return ValidatedData::object(json_decode($captured['result_json'], true, flags: JSON_THROW_ON_ERROR));
        }

        return isset($result['steps'], $result['summary']) ? [] : $result;
    }
}
