<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** The ready step: the PR left draft and, in manual mode, the reviewer was asked. */
final readonly class TaskPullRequestReadyProjection extends TaskPullRequestProjection
{
    public function succeeded(Operation $operation, array $receipt): array
    {
        $this->requireHead($operation, $receipt, 'head');
        $reviewers = $receipt['reviewers'] ?? null;
        if (($receipt['draft'] ?? null) !== false || ! is_array($reviewers) || ! array_is_list($reviewers) || array_any($reviewers, static fn (mixed $login): bool => ! is_string($login))
            || ($operation->input['mode'] === 'manual' && ! array_any($reviewers, static fn (string $login): bool => strcasecmp($login, ValidatedData::string($operation->input['reviewer'])) === 0))) {
            throw new ResourceOperationException('operation.invalid_result', 'The native receipt does not show a ready PR with its requested review.', 422);
        }

        return ['pull_request_number' => $operation->input['pr_number'], 'head_sha' => $operation->input['head_sha'], 'draft' => false, 'reviewers' => $reviewers];
    }

    protected function codes(): array
    {
        return ['tasks_pr.head_moved', 'tasks_pr.ready_refused', 'tasks_pr.review_request_refused'];
    }

    protected function stepCodes(): array
    {
        return ['Take the PR out of draft' => 'tasks_pr.ready_refused', 'Request the review' => 'tasks_pr.review_request_refused'];
    }

    protected function fallback(): string
    {
        return 'tasks_pr.ready_refused';
    }
}
