<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationWorkflowInput;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Task;
use App\Support\ValidatedData;

/** Projects the terminal event of one `operation-task-sync` run: the two commits that the fetch stored, or the step that failed. */
final readonly class TaskSyncProjection implements DirectOperationProjection, DirectOperationWorkflowInput
{
    /**
     * The run starts now, so the Gateway mints the read token now and writes it to the vault key that admission named.
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
        $step = $failed ? TaskPrepResults::failedStep($native, $data) : null;
        $result = $failed ? ['error_code' => 'tasks.sync_failed', 'failed_step' => $step] : self::refs($operation, $native['refs'] ?? null);
        $data['result'] = $result;
        $data['error_code'] = $failed ? 'tasks.sync_failed' : null;

        return app(DirectOperation::class)->project($operation, $data, $result);
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
