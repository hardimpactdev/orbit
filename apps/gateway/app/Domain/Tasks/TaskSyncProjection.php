<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationWorkflowChoice;
use App\Domain\Operations\DirectOperationWorkflowInput;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Task;
use App\Support\ValidatedData;

/**
 * Projects the terminal event of one task:sync run: for a task VM's `operation-task-sync` run the two commits that the fetch stored,
 * for a task copy's `operation-task-sync-copy` run the default head it imported and how far its branch is behind, or the step that
 * failed. It also chooses which of the two workflows admission records for the intent.
 */
final readonly class TaskSyncProjection implements DirectOperationProjection, DirectOperationWorkflowChoice, DirectOperationWorkflowInput
{
    /**
     * A task copy's intent runs operation-task-sync-copy, a task VM's operation-task-sync: Swamp resolves every step's inputs when a run
     * starts, also of a step a guard skips, so one workflow for both could not start for a copy (docs/cli/task/sync.mmd).
     */
    public function workflow(Operation $operation): string
    {
        return isset(ValidatedData::object($operation->input)['copy']) ? 'operation-task-sync-copy' : 'operation-task-sync';
    }

    /**
     * The run starts now, so the Gateway mints the read token now and writes it, as a Git header, to the vault key that admission
     * named: a task VM's clone on Node 209 and a task copy's fetch on its Node send it the same way.
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
        $copy = isset(ValidatedData::object($operation->input)['copy']);
        $result = $failed ? ['error_code' => 'tasks.sync_failed', 'failed_step' => $step]
            : ($copy ? self::base($native['base'] ?? null) : self::refs($operation, $native['refs'] ?? null));
        $data['result'] = $result;
        $data['error_code'] = $failed ? 'tasks.sync_failed' : null;

        return app(DirectOperation::class)->project($operation, $data, $result);
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
     * the branch is behind, the copy's start and its content read, `work` (TaskCopyWork). A run without it records no field.
     *
     * @return array<string,mixed>
     */
    private static function base(mixed $captured): array
    {
        $evidence = is_array($captured) && is_array($captured['evidence'] ?? null) ? $captured['evidence'] : [];

        return array_intersect_key($evidence, array_flip(['default_branch', 'default_commit', 'branch', 'branch_commit', 'behind_by', 'start', 'work']));
    }

    /**
     * The two lines of `git rev-parse`: the commit of the default branch, then the commit of the task branch.
     *
     * @return array<string,mixed>
     */
    private static function refs(Operation $operation, mixed $stdout): array
    {
        $lines = is_string($stdout) ? array_values(array_filter(array_map(trim(...), explode("\n", $stdout)), static fn (string $line): bool => preg_match('/\A[a-f0-9]{40}(?:[a-f0-9]{24})?\z/', $line) === 1)) : [];
        $workspace = ValidatedData::object(ValidatedData::object($operation->input)['workspace']);

        return ['default_branch' => $workspace['default_branch'], 'default_commit' => $lines[0] ?? null, 'branch' => $workspace['branch'], 'branch_commit' => $lines[1] ?? null];
    }
}
