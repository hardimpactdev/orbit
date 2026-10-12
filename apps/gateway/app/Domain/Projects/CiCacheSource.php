<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Project;

interface CiCacheSource
{
    /** @throws ResourceOperationException `instance.ci_caches_unavailable` or `instance.ci_caches_missing` */
    public function fetch(Project $project): CiCacheArchives;
}
