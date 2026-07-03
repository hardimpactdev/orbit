<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles;

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\OrbitAgentJob;
use App\Services\OrbitAgentJobs\OrbitAgentJobDispatcher;

final readonly class NodeRoleAgentJobQueuer
{
    public function __construct(
        private OrbitAgentJobDispatcher $jobs,
    ) {}

    public function queueForAssignment(Node $node, NodeRoleAssignment $assignment, string $role): ?OrbitAgentJob
    {
        if (! $this->shouldQueueAppDevConvergence($node, $role)) {
            return null;
        }

        $tld = $assignment->settings['tld'] ?? null;

        if (! is_string($tld) || trim($tld) === '') {
            return null;
        }

        return $this->jobs->queueAppDevConvergence($node, $tld);
    }

    /**
     * @return array{id: string, type: string, status: string}
     */
    public function toResponsePayload(OrbitAgentJob $job): array
    {
        return [
            'id' => $job->id,
            'type' => $job->type,
            'status' => $job->status,
        ];
    }

    private function shouldQueueAppDevConvergence(Node $node, string $role): bool
    {
        if ($role !== 'app-dev') {
            return false;
        }

        if (! $node->isActive() || ! $node->orbit_agent_capable) {
            return false;
        }

        return $this->isSupportedAppDevAgentPlatform($node->platform);
    }

    private function isSupportedAppDevAgentPlatform(?string $platform): bool
    {
        if (! is_string($platform) || trim($platform) === '') {
            return false;
        }

        $family = strtolower(explode(separator: '_', string: trim($platform), limit: 2)[0]);

        return in_array(needle: $family, haystack: ['darwin', 'linux', 'macos', 'ubuntu'], strict: true);
    }
}
