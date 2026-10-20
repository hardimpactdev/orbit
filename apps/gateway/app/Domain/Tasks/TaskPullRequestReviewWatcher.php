<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Infrastructure\Tasks\HttpTaskPullRequestWatcher;
use App\Models\Task;
use Illuminate\Container\Attributes\Bind;

/** Read-only feedback source. No dispatch, approval, or merge authority. */
#[Bind(HttpTaskPullRequestWatcher::class)]
interface TaskPullRequestReviewWatcher
{
    public function reviews(Task $group, bool $fresh = false): TaskReviewObservation;

    public function reviewCandidate(Task $group, int $reviewId, bool $fresh = false): TaskReviewCandidateResult;

    public function revalidateReviewCandidate(Task $group, TaskReviewCandidate $candidate): TaskReviewCandidateResult;
}
