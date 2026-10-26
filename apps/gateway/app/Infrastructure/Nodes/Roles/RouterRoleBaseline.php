<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Domain\AppDev\AppDevCaddyManager;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\RoleBaseline;
use App\Domain\Nodes\RoleName;
use App\Models\Node;
use App\Models\NodeRole;

final readonly class RouterRoleBaseline implements RoleBaseline
{
    public function __construct(
        private RolePrerequisites $prerequisites,
        private AppDevCaddyManager $caddy,
        private NodeRoleFirewallManager $firewall,
        private ManagedUserAccountResolver $accounts,
    ) {}

    public function converge(Node $node, NodeRole $assignment): void
    {
        $account = $this->accounts->resolve($node);
        $this->prerequisites->converge($node, RoleName::Router, $account, 'router.prerequisite_failed', CaddyRoleFailure::sshLabel(RoleName::Router));
        $this->caddy->converge($node, RoleName::Router);
        $this->firewall->converge($node, RoleName::Router, $account->user);
    }

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void
    {
        $account = $this->accounts->resolve($node);
        // The build renders every Route site that stored state still places on the Node.
        $this->caddy->remove($node, RoleName::Router);
        $this->firewall->remove($node, RoleName::Router, $account->user);
    }

    public function removeUnreachable(Node $node, NodeRole $assignment): void {}
}
