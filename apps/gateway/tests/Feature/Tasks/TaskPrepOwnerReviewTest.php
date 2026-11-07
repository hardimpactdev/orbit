<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubReview;
use App\Domain\GitHub\GitHubReviewComment;
use App\Domain\GitHub\GitHubReviewState;
use App\Domain\Tasks\TaskPrepOwnerReview;

function ownerReview(string $body): GitHubReview
{
    return new GitHubReview(31, 1, 'nick', GitHubReviewState::ChangesRequested, str_repeat('a', 40), new DateTimeImmutable('2026-11-06 10:00:00'), 'https://github.com/hardimpactdev/orbit/pull/7#pullrequestreview-31', $body);
}

function ownerComment(string $login, string $body, ?string $path = 'docs/cli/firewall/allow.mmd', ?int $line = 12, ?int $original = null): GitHubReviewComment
{
    return new GitHubReviewComment(id: 1, reviewId: 31, authorId: 1, authorLogin: $login, url: 'https://github.com/x', body: $body, path: $path, diffHunk: null, line: $line, startLine: null, originalLine: $original,
        originalStartLine: null, side: null, startSide: null, position: null, originalPosition: null, commitId: null, originalCommitId: null, inReplyToId: null, subjectType: null, createdAt: null, updatedAt: null);
}

it('puts the review text and the inline comments of the reviewer into the corrections', function (): void {
    $text = TaskPrepOwnerReview::corrections(ownerReview("  Two findings.\n"), [
        ownerComment('nick', 'Name the error code.'),
        ownerComment('orbit-app[bot]', 'A reply that is not his.'),
        ownerComment('nick', 'Outdated.', original: 9, line: null),
        ownerComment('nick', 'A note on the whole file.', path: null),
        ownerComment('nick', '   '),
    ], 'nick');

    expect($text)->toBe("Two findings.\n\ndocs/cli/firewall/allow.mmd:12: Name the error code.\n\ndocs/cli/firewall/allow.mmd:9: Outdated.\n\nComment: A note on the whole file.");
});

it('points to the review when the owner wrote no text', function (): void {
    expect(TaskPrepOwnerReview::corrections(ownerReview(''), [], 'nick'))->toBe('The owner requested changes and wrote no text: https://github.com/hardimpactdev/orbit/pull/7#pullrequestreview-31');
});

it('cuts the corrections at the limit of task:prep-fix, whole characters only, and says so', function (string $text): void {
    $corrections = TaskPrepOwnerReview::corrections(ownerReview($text), [], 'nick');

    expect(strlen($corrections))->toBeLessThanOrEqual(TaskPrepOwnerReview::Limit)
        ->and(mb_check_encoding($corrections, 'UTF-8'))->toBeTrue()
        ->and($corrections)->toEndWith('at the limit of the corrections.]');
})->with(['ascii' => [str_repeat('a', 50000)], 'multibyte' => [str_repeat('é', 30000)]]);

it('keeps a review that fits the limit whole', function (): void {
    expect(TaskPrepOwnerReview::corrections(ownerReview(str_repeat('a', TaskPrepOwnerReview::Limit)), [], 'nick'))->toBe(str_repeat('a', TaskPrepOwnerReview::Limit));
});
