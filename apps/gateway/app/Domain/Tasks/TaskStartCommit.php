<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Project;

/**
 * The commit a task workspace starts from ([Provision an external group](/reference/tasks#provision-an-external-group)):
 * the newest commit of the Project's default branch that passed its `merge_check`, or the branch head when the Project
 * has none. Provision admission reads it before its transaction, so a group never starts on a red commit.
 */
interface TaskStartCommit
{
    /**
     * @return string the full commit SHA
     *
     * @throws ResourceOperationException `tasks.no_green_commit` (409) when no examined commit passed,
     *                                    `tasks.source_unreadable` (409) when the merge check cannot be read through the GitHub App,
     *                                    `tasks.source_unavailable` (502) when GitHub or the repository cannot answer
     */
    public function resolve(Project $project): string;
}
