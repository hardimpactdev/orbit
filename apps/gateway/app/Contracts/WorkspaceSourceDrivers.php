<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Project;

interface WorkspaceSourceDrivers
{
    public function resolve(Project $app, AppInstance $instance): WorkspaceSourceDriver;

    public function effectiveAdapter(AppInstance $instance): ?string;

    /**
     * @return array{label: string, done_label: string}
     */
    public function progressLabels(AppInstance $instance, Node $node): array;
}
