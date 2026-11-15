<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Models\Project;

/**
 * A plug-in point: the store of a Project's own agent instructions ([Extensions](/reference/extensions#plug-in-points)). The documents
 * extension answers from the Project's `agent/` folder ([Agent instructions](/reference/development-containers#agent-instructions)).
 * A source whose extension is disabled answers as if the Project had none.
 */
interface ProjectAgentInstructionSource
{
    /**
     * The files of the folder, by path relative to it (`AGENTS.md`, `.skills/<skill>/SKILL.md`), with no archived entry: each one's size
     * from its record, and a reader of its body. The caller reads only what it takes, outside a database transaction; a store failure
     * is a ResourceOperationException.
     *
     * @return array<string, array{bytes: int, read: \Closure(): string}>
     */
    public function files(Project $project): array;

    /**
     * The same folder from its records alone, without a body read: the current version number of `AGENTS.md` (null when absent) and the
     * names of the skill folders, in name order. Null when the folder holds neither.
     *
     * @return array{agents_md_version: int|null, skills: list<string>}|null
     */
    public function summary(Project $project): ?array;
}
