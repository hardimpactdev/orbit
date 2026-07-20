<?php

declare(strict_types=1);

namespace App\Services\Runtime;

use App\Models\Project;
use App\Models\Workspace;

final readonly class OrbitHostCwdContext
{
    public function __construct(
        public Project $app,
        public ?Workspace $workspace,
        public string $hostCwd,
    ) {}
}
