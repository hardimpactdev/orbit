<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Models\Instance;
use App\Models\Node;
use App\Models\Schedule;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Workspaces\WorkspacePlacement;

final readonly class DoctorScheduleNodeResolver
{
    public function __construct(
        private WorkspacePlacement $workspacePlacement,
        private NodeRoleAssignments $nodeRoleAssignments,
    ) {}

    public function nameFor(Schedule $schedule): ?string
    {
        $schedule->loadMissing(['app', 'instance', 'node']);

        if ($schedule->scope === 'app') {
            return $schedule->instance instanceof Instance
                ? $this->workspacePlacement->nodeForInstance($schedule->instance)?->name
                : null;
        }

        if ($schedule->scope === 'node') {
            return $schedule->node?->name;
        }

        if ($schedule->scope === 'orbit') {
            $node = $this->nodeRoleAssignments->activeGatewayNodeQuery()->first();

            return $node instanceof Node ? $node->name : null;
        }

        return null;
    }
}
