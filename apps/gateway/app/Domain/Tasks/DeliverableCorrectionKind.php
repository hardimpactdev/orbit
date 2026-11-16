<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/** The ways a started subtask's deliverables may change (docs/reference/tasks.md#correct-the-deliverables-of-a-started-subtask). */
enum DeliverableCorrectionKind
{
    /** The deliverable path gate holds the subtask before its implementer starts. */
    case PathGate;

    /** The one correction after a failed `invalid_deliverable` step. */
    case InvalidDeliverable;

    /** A correction with a reason after the newest handoff check of a task copy's current subtask failed. */
    case FailedCopyCheck;
}
