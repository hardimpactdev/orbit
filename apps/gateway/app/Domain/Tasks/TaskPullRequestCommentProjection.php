<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

/** The comment step: the body was posted on the PR as a comment of the App. */
final readonly class TaskPullRequestCommentProjection extends TaskPullRequestProjection
{
    public function succeeded(Operation $operation, array $receipt): array
    {
        $comment = $receipt['comment'] ?? null;
        if (! is_int($comment) || $comment < 1) {
            throw new ResourceOperationException('operation.invalid_result', 'The native receipt does not show the posted comment.', 422);
        }

        return ['pull_request_number' => $operation->input['pr_number'], 'head_sha' => $operation->input['head_sha'], 'comment' => $comment];
    }

    protected function codes(): array
    {
        return ['tasks_pr.head_moved', 'tasks_pr.comment_refused'];
    }

    protected function stepCodes(): array
    {
        return ['Post the comment' => 'tasks_pr.comment_refused'];
    }

    protected function fallback(): string
    {
        return 'tasks_pr.comment_refused';
    }
}
