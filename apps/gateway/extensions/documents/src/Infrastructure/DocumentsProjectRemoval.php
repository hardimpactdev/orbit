<?php

declare(strict_types=1);

namespace Orbit\Extensions\Documents\Infrastructure;

use App\Domain\Projects\ProjectRemovalHook;
use App\Models\Project;
use Orbit\Extensions\Documents\Actions\DocumentTreeAction;

/** Removes a Project's documents with the Project, and records their storage keys for cleanup. */
final readonly class DocumentsProjectRemoval implements ProjectRemovalHook
{
    public function __construct(private DocumentTreeAction $tree) {}

    public function lock(Project $project): void
    {
        $this->tree->lock();
    }

    public function remove(Project $project): void
    {
        $this->tree->removeProject($project->id);
    }
}
