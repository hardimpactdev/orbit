<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Node;
use App\Models\Project;
use App\Models\Workspace;

interface PhpRuntimeArtifactConverger
{
    /**
     * @return list<array<string, string>>
     */
    public function forApp(Project $app): array;

    /**
     * @return list<array<string, string>>
     */
    public function forWorkspace(Workspace $workspace, Node $node): array;
}
