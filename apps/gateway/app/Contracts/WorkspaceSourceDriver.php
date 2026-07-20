<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\Workspaces\WorkspaceProvisionResult;
use App\Models\Node;
use App\Models\Project;

interface WorkspaceSourceDriver
{
    public function create(Project $app, Node $node, string $name, string $base): WorkspaceProvisionResult;
}
