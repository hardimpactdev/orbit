<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubPullRequest;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\Tasks\TaskHandoffPullRequest;

const HANDOFF_PR_SHA = '1111111111111111111111111111111111111111';

it('is current only while the pull request is open with the handoff commit as its head', function (GitHubPullRequestState $state, string $head, bool $current): void {
    $pullRequest = new GitHubPullRequest($state, false, 'dirty', $head, 'main');

    expect(TaskHandoffPullRequest::of($pullRequest, HANDOFF_PR_SHA))->toEqual(new TaskHandoffPullRequest($current, $current ? 'main' : null));
})->with([
    'open with the commit' => [GitHubPullRequestState::Open, HANDOFF_PR_SHA, true],
    'open with another head' => [GitHubPullRequestState::Open, str_repeat('2', 40), false],
    'closed' => [GitHubPullRequestState::Closed, HANDOFF_PR_SHA, false],
]);

it('names no conflict while GitHub has not computed whether the pull request merges', function (): void {
    expect(TaskHandoffPullRequest::of(new GitHubPullRequest(GitHubPullRequestState::Open, null, 'unknown', HANDOFF_PR_SHA, 'main'), HANDOFF_PR_SHA))
        ->toEqual(new TaskHandoffPullRequest(true, null));
});
