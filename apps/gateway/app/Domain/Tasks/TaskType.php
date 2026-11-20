<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

enum TaskType: string
{
    case Implementation = 'implementation';

    /** ADR 0203: Orbit's review of the whole branch before it pushes. It has no implementer. */
    case FinalReview = 'final_review';

    /**
     * The work of a task with no subtask: the one row that holds the task's deliverables, checks, turns and review. Calls by the
     * task's own id address it, and no read shows it as a subtask (docs/reference/tasks.md#a-task-with-no-subtask).
     */
    case Work = 'work';
}
