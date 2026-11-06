<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/** How the gate of a task pull request is passed: Nick's approval of the head, or the review rounds of a fresh reviewer. */
enum TaskReviewMode: string
{
    case Manual = 'manual';
    case Automatic = 'automatic';
}
