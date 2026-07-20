<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Project;
use HardImpact\OpenCode\OpenCode;

interface OpenCodeClientFactory
{
    public function forApp(Project $app): OpenCode;
}
