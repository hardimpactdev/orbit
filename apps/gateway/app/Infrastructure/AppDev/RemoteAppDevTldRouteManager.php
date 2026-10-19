<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\AppDevTldRouteManager;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\WireGuard\WireGuardPeerDnsRepairer;
use App\Models\Node;

final readonly class RemoteAppDevTldRouteManager implements AppDevTldRouteManager
{
    public function __construct(private WireGuardPeerDnsRepairer $repairer) {}

    public function converge(Node $node): void
    {
        if (! is_string($node->tld) || $node->tld === '') {
            throw new RuntimeConvergenceException('app-dev-tld-route', 'app-dev.tld_route_failed', "Node [{$node->name}] has no app development TLD.");
        }
        try {
            $this->repairer->repair($node);
        } catch (NodeProvisioningException $exception) {
            throw new RuntimeConvergenceException('app-dev-tld-route', 'app-dev.tld_route_failed', $exception->getMessage(), $exception, $exception->result);
        }
    }
}
