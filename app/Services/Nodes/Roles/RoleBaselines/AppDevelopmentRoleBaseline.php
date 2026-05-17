<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles\RoleBaselines;

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\DevelopmentDnsMappingEnactor;
use RuntimeException;

class AppDevelopmentRoleBaseline implements RoleBaseline
{
    public function __construct(
        private readonly DevelopmentDnsMappingEnactor $developmentDnsMappingEnactor = new DevelopmentDnsMappingEnactor,
    ) {}

    public function converge(Node $node, NodeRoleAssignment $assignment): void
    {
        $tld = $assignment->settings['tld'] ?? null;

        if (! is_string($tld) || trim($tld) === '') {
            throw new RuntimeException('The app-development role requires a non-empty tld setting.');
        }

        $result = $this->developmentDnsMappingEnactor->convergeDevelopmentRole($node, $tld);

        if (($result['status'] ?? null) !== 'not_applicable') {
            return;
        }

        throw new RuntimeException('The app-development role requires a WireGuard address so the development DNS mapping can be materialized.');
    }

    public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
    {
        $tld = $assignment->settings['tld'] ?? null;

        if (! is_string($tld) || trim($tld) === '') {
            return;
        }

        $result = $this->developmentDnsMappingEnactor->removeDevelopmentRole($node, $tld);

        if (($result['status'] ?? null) !== 'failed') {
            return;
        }

        $reason = $result['reason'] ?? 'Failed to remove development DNS mapping.';

        throw new RuntimeException(is_string($reason) ? $reason : 'Failed to remove development DNS mapping.');
    }
}
