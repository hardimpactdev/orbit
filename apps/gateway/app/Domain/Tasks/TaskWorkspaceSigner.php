<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Instance;

/**
 * Commits the whole workspace of a Task group's shared checkout: a handoff for CI (/reference/tasks#handoff-ci), or an
 * approved subtask in a Project without a merge check. With nothing to commit, it returns HEAD.
 */
interface TaskWorkspaceSigner
{
    /** With `$allowEmpty`, a workspace with nothing to commit still gets a new commit on top of HEAD. */
    public function commit(Instance $instance, string $message, bool $allowEmpty = false): ?string;
}
