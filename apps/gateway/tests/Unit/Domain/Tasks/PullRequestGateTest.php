<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubReview;
use App\Domain\GitHub\GitHubReviewState;
use App\Domain\Tasks\PullRequestGate;
use App\Domain\Tasks\PullRequestGateState;

const PULL_REQUEST_GATE_UNIT_HEAD = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';
const PULL_REQUEST_GATE_UNIT_OLD_HEAD = '0000000000000000000000000000000000000001';

function gate_review(int $id, string $login, GitHubReviewState $state, string $commit, ?string $at): GitHubReview
{
    return new GitHubReview($id, $id, $login, $state, $commit, $at === null ? null : new DateTimeImmutable($at), 'https://github.com/o/r/pull/1#pullrequestreview-'.$id, '');
}

it('waits while the reviewer has not decided', function (): void {
    expect(PullRequestGate::manual([], PULL_REQUEST_GATE_UNIT_HEAD, 'nckrtl'))->toBe(PullRequestGateState::Waiting)
        ->and(PullRequestGate::manual([gate_review(1, 'nckrtl', GitHubReviewState::Commented, PULL_REQUEST_GATE_UNIT_HEAD, '2026-11-05T10:00:00Z')], PULL_REQUEST_GATE_UNIT_HEAD, 'nckrtl'))->toBe(PullRequestGateState::Waiting)
        ->and(PullRequestGate::manual([gate_review(2, 'nckrtl', GitHubReviewState::Pending, PULL_REQUEST_GATE_UNIT_HEAD, null)], PULL_REQUEST_GATE_UNIT_HEAD, 'nckrtl'))->toBe(PullRequestGateState::Waiting);
});

it('passes on the reviewer approval of the exact head, in any letter case', function (): void {
    $reviews = [gate_review(1, 'NckRTL', GitHubReviewState::Approved, PULL_REQUEST_GATE_UNIT_HEAD, '2026-11-05T10:00:00Z')];

    expect(PullRequestGate::manual($reviews, PULL_REQUEST_GATE_UNIT_HEAD, 'nckrtl'))->toBe(PullRequestGateState::Approved);
});

it('ignores approvals of other people', function (): void {
    $reviews = [gate_review(1, 'someone-else', GitHubReviewState::Approved, PULL_REQUEST_GATE_UNIT_HEAD, '2026-11-05T10:00:00Z')];

    expect(PullRequestGate::manual($reviews, PULL_REQUEST_GATE_UNIT_HEAD, 'nckrtl'))->toBe(PullRequestGateState::Waiting);
});

it('voids an approval of an older head', function (): void {
    $reviews = [gate_review(1, 'nckrtl', GitHubReviewState::Approved, PULL_REQUEST_GATE_UNIT_OLD_HEAD, '2026-11-05T10:00:00Z')];

    expect(PullRequestGate::manual($reviews, PULL_REQUEST_GATE_UNIT_HEAD, 'nckrtl'))->toBe(PullRequestGateState::HeadMoved);
});

it('follows the latest decision of the reviewer', function (): void {
    $approvedThenChanges = [
        gate_review(2, 'nckrtl', GitHubReviewState::ChangesRequested, PULL_REQUEST_GATE_UNIT_HEAD, '2026-11-05T11:00:00Z'),
        gate_review(1, 'nckrtl', GitHubReviewState::Approved, PULL_REQUEST_GATE_UNIT_HEAD, '2026-11-05T10:00:00Z'),
    ];
    $changesThenApproved = [
        gate_review(1, 'nckrtl', GitHubReviewState::ChangesRequested, PULL_REQUEST_GATE_UNIT_OLD_HEAD, '2026-11-05T10:00:00Z'),
        gate_review(2, 'nckrtl', GitHubReviewState::Approved, PULL_REQUEST_GATE_UNIT_HEAD, '2026-11-05T11:00:00Z'),
        gate_review(3, 'nckrtl', GitHubReviewState::Commented, PULL_REQUEST_GATE_UNIT_HEAD, '2026-11-05T12:00:00Z'),
    ];
    $dismissed = [
        gate_review(1, 'nckrtl', GitHubReviewState::Approved, PULL_REQUEST_GATE_UNIT_HEAD, '2026-11-05T10:00:00Z'),
        gate_review(2, 'nckrtl', GitHubReviewState::Dismissed, PULL_REQUEST_GATE_UNIT_HEAD, '2026-11-05T11:00:00Z'),
    ];

    expect(PullRequestGate::manual($approvedThenChanges, PULL_REQUEST_GATE_UNIT_HEAD, 'nckrtl'))->toBe(PullRequestGateState::ChangesRequested)
        ->and(PullRequestGate::manual($changesThenApproved, PULL_REQUEST_GATE_UNIT_HEAD, 'nckrtl'))->toBe(PullRequestGateState::Approved)
        ->and(PullRequestGate::manual($dismissed, PULL_REQUEST_GATE_UNIT_HEAD, 'nckrtl'))->toBe(PullRequestGateState::Waiting);
});

it('breaks a tie of submit times by review id', function (): void {
    $reviews = [
        gate_review(9, 'nckrtl', GitHubReviewState::Approved, PULL_REQUEST_GATE_UNIT_HEAD, '2026-11-05T10:00:00Z'),
        gate_review(8, 'nckrtl', GitHubReviewState::ChangesRequested, PULL_REQUEST_GATE_UNIT_HEAD, '2026-11-05T10:00:00Z'),
    ];

    expect(PullRequestGate::manual($reviews, PULL_REQUEST_GATE_UNIT_HEAD, 'nckrtl'))->toBe(PullRequestGateState::Approved);
});
