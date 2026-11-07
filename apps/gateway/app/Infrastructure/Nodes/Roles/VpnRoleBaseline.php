<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\RoleBaseline;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\NodeRole;

final readonly class VpnRoleBaseline implements RoleBaseline
{
    public function __construct(
        private RolePrerequisites $prerequisites,
        private NodeRoleFirewallManager $firewall,
        private ManagedUserAccountResolver $accounts,
        private PrivateDnsManager $dns,
    ) {}

    public function converge(Node $node, NodeRole $assignment): void
    {
        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            throw new NodeRoleOperationException(
                'role-prerequisites',
                'node_role.convergence_failed',
                'vpn.wireguard_ip_missing',
                "Node [{$node->name}] has no WireGuard address.",
            );
        }

        $account = $this->accounts->resolve($node);

        $result = $this->prerequisites->install($node, RoleName::Vpn, $account);

        if (! $result->succeeded()) {
            throw new NodeRoleOperationException(
                'role-prerequisites',
                'node_role.convergence_failed',
                'vpn.prerequisite_failed',
                "VPN role prerequisites failed on node [{$node->name}].",
                $result,
            );
        }

        $this->firewall->converge($node, RoleName::Vpn, $node->user);

        // Role convergence claims the assignment as provisioning while its VPN Node stays active. dnsmasq is a role prerequisite;
        // the private DNS publication writes its files. A Node that is not an active VPN Node yet gets them from its role run.
        if ($node->status === LifecycleStatus::Active) {
            $this->dns->converge();
        }
    }

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): never
    {
        throw new NodeRoleValidationException('The VPN role cannot be removed.');
    }

    public function removeUnreachable(Node $node, NodeRole $assignment): never
    {
        throw new NodeRoleValidationException('The VPN role cannot be removed.');
    }
}
