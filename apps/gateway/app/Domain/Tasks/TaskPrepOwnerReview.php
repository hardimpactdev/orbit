<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\GitHub\GitHubReview;
use App\Domain\GitHub\GitHubReviewComment;

/** The corrections a request for changes on a draft spec holds: the text of the review and the inline comments of the reviewer. */
final readonly class TaskPrepOwnerReview
{
    /** The longest `corrections` text that `task:prep-fix` takes. */
    public const int Limit = 40000;

    private const string Cut = "\n\n[The review was cut here at the limit of the corrections.]";

    /** @param list<GitHubReviewComment> $comments */
    public static function corrections(GitHubReview $review, array $comments, string $reviewer): string
    {
        $lines = trim($review->body) === '' ? [] : [trim($review->body)];
        foreach ($comments as $comment) {
            if (strcasecmp($comment->authorLogin, $reviewer) === 0 && trim($comment->body) !== '') {
                $line = $comment->line ?? $comment->originalLine;
                $lines[] = ($comment->path === null ? 'Comment' : $comment->path.($line === null ? '' : ':'.$line)).': '.trim($comment->body);
            }
        }
        $text = $lines === [] ? 'The owner requested changes and wrote no text: '.$review->url : implode("\n\n", $lines);

        return strlen($text) <= self::Limit ? $text : mb_strcut($text, 0, self::Limit - strlen(self::Cut), 'UTF-8').self::Cut;
    }
}
