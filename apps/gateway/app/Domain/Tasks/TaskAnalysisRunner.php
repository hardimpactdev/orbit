<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Instance;

/** Provision reads the initial base until its native VM adapter replaces this last caller. */
interface TaskAnalysisRunner
{
    /** Initial origin/main merge base, recorded once by provision; null if unavailable. */
    public function initialAnalysisBase(Instance $instance): ?string;
}
