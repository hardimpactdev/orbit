<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

enum TaskCommentType: string
{
    case ReadyForReview = 'ready_for_review';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
    case Blocked = 'blocked';
    case Answered = 'answered';
    // History only: reviewers asked for a TASK-<group> topology before declared topologies replaced it.
    case TopologyRequested = 'topology_requested';
    case AssistanceRequested = 'assistance_requested';
    case Resolution = 'resolution';
    case QuestionClosed = 'question_closed';
    case ProviderRetry = 'provider_retry';
}
