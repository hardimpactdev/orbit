<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubCheckConclusion;
use App\Domain\GitHub\GitHubCheckFault;
use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubCheckSuite;

it('decides who answers for every conclusion, on a check run and on a suite without the merge check', function (GitHubCheckConclusion $conclusion, GitHubCheckFault $run, GitHubCheckFault $suite): void {
    expect($conclusion->ofRun())->toBe($run)
        ->and($conclusion->ofSuiteWithoutMergeCheck())->toBe($suite)
        ->and((new GitHubCheckRun('Tests', $conclusion->value, null))->fault())->toBe($run)
        ->and((new GitHubCheckSuite(1, 'completed', $conclusion->value, 'github-actions'))->faultWithoutMergeCheck())->toBe($suite);
})->with([
    'success' => [GitHubCheckConclusion::Success, GitHubCheckFault::None, GitHubCheckFault::Unexplained],
    'neutral' => [GitHubCheckConclusion::Neutral, GitHubCheckFault::None, GitHubCheckFault::Unexplained],
    'skipped' => [GitHubCheckConclusion::Skipped, GitHubCheckFault::None, GitHubCheckFault::Unexplained],
    'stale' => [GitHubCheckConclusion::Stale, GitHubCheckFault::None, GitHubCheckFault::Unexplained],
    'failure' => [GitHubCheckConclusion::Failure, GitHubCheckFault::Change, GitHubCheckFault::Change],
    'timed_out' => [GitHubCheckConclusion::TimedOut, GitHubCheckFault::Change, GitHubCheckFault::Unexplained],
    'action_required' => [GitHubCheckConclusion::ActionRequired, GitHubCheckFault::Change, GitHubCheckFault::Unexplained],
    'cancelled' => [GitHubCheckConclusion::Cancelled, GitHubCheckFault::Infrastructure, GitHubCheckFault::Infrastructure],
    // A run that no runner started is infrastructure; a workflow GitHub could not start is a fault of its file.
    'startup_failure' => [GitHubCheckConclusion::StartupFailure, GitHubCheckFault::Infrastructure, GitHubCheckFault::Change],
]);

it('judges a run that has not completed, or a conclusion GitHub adds later, as no failure, and such a suite as unexplained', function (): void {
    expect((new GitHubCheckRun('Tests', null, null))->fault())->toBe(GitHubCheckFault::None)
        ->and((new GitHubCheckRun('Tests', 'something_new', null))->failed())->toBeFalse()
        ->and((new GitHubCheckSuite(1, 'completed', 'something_new', 'github-actions'))->faultWithoutMergeCheck())->toBe(GitHubCheckFault::Unexplained);
});
