<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Project;

interface AppRuntimeUserResolver
{
    public function forApp(Project $app): string;
}
