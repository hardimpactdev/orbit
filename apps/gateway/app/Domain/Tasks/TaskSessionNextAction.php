<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

enum TaskSessionNextAction: string
{
    case DrainApproval = 'drain_approval';
    case DrainUserInput = 'drain_user_input';
    case ContinueImplementer = 'continue_implementer';
    case RelayReviewToImplementer = 'relay_review_to_implementer';
    case MarkSubtaskDone = 'mark_subtask_done';
    case SettleGroup = 'settle_group';
    case EscalateCoder = 'escalate_coder';
    case Noop = 'noop';

}
