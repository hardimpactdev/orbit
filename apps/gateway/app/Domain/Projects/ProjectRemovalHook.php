<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Models\Project;

/**
 * A plug-in point: an extension's part in removing a Project ([Extensions](/reference/extensions#plug-in-points)).
 *
 * Both methods run inside the removal transaction, whether or not the extension is enabled.
 */
interface ProjectRemovalHook
{
    /** Runs first, before the guards, so the extension's writers wait for the removal. */
    public function lock(Project $project): void;

    /**
     * Runs after every guard passed and before the Project row is deleted. A ResourceOperationException refuses the
     * removal and rolls it back.
     */
    public function remove(Project $project): void;
}
