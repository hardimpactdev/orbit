<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskPullRequestCommentProjection;
use App\Domain\Tasks\TaskReviewRoundComment;
use App\Domain\Tasks\TaskReviewRoundVerdict;
use App\Models\Operation;
use App\Models\TaskReviewRound;

it('writes the verdict and the findings of a round as the comment', function (): void {
    $findings = new TaskReviewRound(['round' => 2, 'head_sha' => str_repeat('a', 40), 'verdict' => TaskReviewRoundVerdict::Findings, 'summary' => "docs/a.md: missing case\ncode/b.php: no test"]);
    $pass = new TaskReviewRound(['round' => 1, 'head_sha' => str_repeat('b', 40), 'verdict' => TaskReviewRoundVerdict::Pass, 'summary' => null]);
    $failed = new TaskReviewRound(['round' => 3, 'head_sha' => str_repeat('c', 40), 'verdict' => TaskReviewRoundVerdict::Failed]);

    expect(TaskReviewRoundComment::body($findings))->toBe("**Review round 2** of `aaaaaaa`: **findings**\n\ndocs/a.md: missing case\ncode/b.php: no test")
        ->and(TaskReviewRoundComment::body($pass))->toBe('**Review round 1** of `bbbbbbb`: **pass**')
        ->and(TaskReviewRoundComment::body($failed))->toBe('**Review round 3** of `ccccccc`: **no verdict**');
});

it('registers the comment step as a native Swamp kind with its workflow and token key', function (): void {
    $kind = app(DirectOperation::class)->definitions()[OperationKinds::TasksPrComment];

    expect($kind['workflow'])->toBe('operation-task-pr-comment')
        ->and($kind['projection'])->toBe(TaskPullRequestCommentProjection::class)
        ->and($kind['execution_copies'])->toBe([['vault' => 'orbit-control-inputs', 'key_pattern' => '^pr-comment-write-[a-f0-9-]{36}$']]);
});

it('records the comment receipt only with a comment id', function (): void {
    $projection = new TaskPullRequestCommentProjection;
    $operation = (new Operation)->forceFill(['input' => ['pr_number' => 57, 'head_sha' => str_repeat('a', 40)]]);

    expect($projection->succeeded($operation, ['comment' => 991]))->toBe(['pull_request_number' => 57, 'head_sha' => str_repeat('a', 40), 'comment' => 991])
        ->and(fn () => $projection->succeeded($operation, []))->toThrow(ResourceOperationException::class)
        ->and(fn () => $projection->succeeded($operation, ['comment' => 'x']))->toThrow(ResourceOperationException::class);
});
