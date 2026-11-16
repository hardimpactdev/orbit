<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\TaskCheck;

/** The correction that may change a started subtask's deliverables now, and the failed check it answers. */
final readonly class DeliverableCorrection
{
    public function __construct(public DeliverableCorrectionKind $kind, public ?TaskCheck $check = null) {}

    /** Only a correction after a failed check of a task copy takes a reason, and it needs one. */
    public function takesReason(): bool
    {
        return $this->kind === DeliverableCorrectionKind::FailedCopyCheck;
    }

    /** The same correction of the same check, as it was read before the locks. */
    public function sameAs(?self $other): bool
    {
        return $other instanceof self && $other->kind === $this->kind && $other->check?->id === $this->check?->id;
    }
}
