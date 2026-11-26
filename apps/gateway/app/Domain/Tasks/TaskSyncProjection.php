<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Engine\EngineRun;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationWorkflowInput;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Task;
use App\Support\ValidatedData;

/**
 * Projects a task copy's sync: the default head it imported and how far its branch is behind, or the step that failed.
 */
final readonly class TaskSyncProjection implements DirectOperationProjection, DirectOperationWorkflowInput
{
    /** The import's own refusals, which name the failure in place of `tasks.sync_failed`. */
    private const array RefusalCodes = ['tasks.default_branch_missing', 'tasks.base_ref_mismatch'];

    /**
     * The run starts now, so the Gateway mints the read token now and writes it, as a Git header, to the vault key that admission
     * named for the copy's fetch on its Node.
     *
     * @return array<string,mixed>
     */
    public function workflowInput(Operation $operation): array
    {
        $group = Task::topLevel()->with('project')->find($operation->target_id);
        if (! $group instanceof Task) {
            throw new ResourceOperationException('tasks.no_workspace', 'The task group is gone, so the sync cannot start.', 409);
        }
        app(TaskGitHubTokens::class)->writeClone($group, ValidatedData::object(ValidatedData::object($operation->input)['git_authorization']));

        return $this->input($operation);
    }

    /** @return array<string,mixed> */
    public function input(Operation $operation): array
    {
        return ['intent' => ValidatedData::object($operation->input)];
    }

    /** @param array<string,mixed> $data */
    public function apply(Operation $operation, array $data): Operation
    {
        $native = ValidatedData::object($data['result'] ?? []);
        $failed = in_array($data['status'] ?? null, ['failed', 'interrupted'], true);
        $step = $failed ? self::failedStep($native, $data) : null;
        $result = $failed ? ['error_code' => 'tasks.sync_failed', 'failed_step' => $step]
            : self::base($native['base'] ?? null);
        $data['result'] = $result;
        $data['error_code'] = $failed ? 'tasks.sync_failed' : null;

        return app(DirectOperation::class)->project($operation, $data, $result);
    }

    /**
     * An engine run's end (docs/cli/task/engine/sync.mmd), through the same result as a Swamp run: the import's receipt holds the
     * base evidence, and a failed step names the Swamp step it stands for. A deterministic refusal of the import keeps its own code.
     */
    public function applyEngine(Operation $operation, EngineRun $run): Operation
    {
        app(DirectOperation::class)->assertRun($operation, $run->runId());
        $step = $run->failed();
        if ($step === null && $run->succeeded()) {
            $evidence = json_decode((string) $run->output('sync'), true);

            return $this->apply($operation, [...$run->times(), 'status' => 'succeeded',
                'result' => ['base' => ['evidence' => is_array($evidence) ? $evidence : []]]]);
        }
        $code = $step !== null && in_array($step->error_code, self::RefusalCodes, true) ? $step->error_code : 'tasks.sync_failed';
        $failed = $step === null ? null : TaskSyncEngineFlow::label($step->name);
        $result = ['error_code' => $code, 'failed_step' => $failed];

        return app(DirectOperation::class)->project($operation, [...$run->times(), 'status' => $step === null ? 'cancelled' : 'failed',
            'failure' => ['failed_step' => $failed], 'error_code' => $code, 'result' => $result], $result);
    }

    /**
     * The step that ended a failed run: the one the failure names, else the first failed step of the summary.
     *
     * @param  array<string,mixed>  $native
     * @param  array<string,mixed>  $data
     */
    private static function failedStep(array $native, array $data): ?string
    {
        $failure = ValidatedData::object($data['failure'] ?? []);
        $steps = ValidatedData::object($native['summary'] ?? [])['failed_steps'] ?? [];
        $step = $failure['failed_step'] ?? (is_array($steps) ? ($steps[0] ?? null) : null);

        return is_string($step) ? $step : null;
    }

    /**
     * The evidence of the copy's base phase: the default head now in `refs/remotes/origin/<default>`, the branch commit, how far
     * the branch is behind, the copy's start and its content read, `work` (TaskWork). A run without it records no field.
     *
     * @return array<string,mixed>
     */
    private static function base(mixed $captured): array
    {
        $evidence = is_array($captured) && is_array($captured['evidence'] ?? null) ? $captured['evidence'] : [];

        return array_intersect_key($evidence, array_flip(['default_branch', 'default_commit', 'branch', 'branch_commit', 'behind_by', 'start', 'work']));
    }
}
