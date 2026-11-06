<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/** What one review round of the automatic mode found on one pull request head. */
enum TaskReviewRoundVerdict: string
{
    case Pass = 'pass';
    case Findings = 'findings';
    case Failed = 'failed';
}
