<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Instance;

/** Restores the checked commit and removes untracked files after an external review turn. */
interface TaskReviewReset
{
    public function reset(Instance $instance, string $commit): void;
}
