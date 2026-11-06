<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

/** The merge step: the PR was squashed into its base branch on the admitted head. */
final readonly class TaskPullRequestMergeProjection extends TaskPullRequestProjection
{
    public function succeeded(Operation $operation, array $receipt): array
    {
        $sha = $receipt['sha'] ?? null;
        if (($receipt['merged'] ?? null) !== true || ! is_string($sha) || preg_match('/\A[0-9a-f]{40}\z/D', $sha) !== 1) {
            throw new ResourceOperationException('operation.invalid_result', 'The native receipt does not show a merged PR with its merge commit.', 422);
        }

        return ['pull_request_number' => $operation->input['pr_number'], 'head_sha' => $operation->input['head_sha'], 'merged' => true, 'merge_sha' => $sha];
    }

    protected function codes(): array
    {
        return ['tasks_pr.merge_refused'];
    }

    protected function stepCodes(): array
    {
        return ['Squash on expected head' => 'tasks_pr.merge_refused'];
    }

    protected function fallback(): string
    {
        return 'tasks_pr.merge_refused';
    }
}
