<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/** What the manual gate sees on a pull request head. */
enum PullRequestGateState: string
{
    case Approved = 'approved';
    case Waiting = 'waiting';
    case ChangesRequested = 'changes_requested';
    case HeadMoved = 'head_moved';
}
