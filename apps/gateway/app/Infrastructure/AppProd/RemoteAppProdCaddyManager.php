<?php

declare(strict_types=1);

namespace App\Infrastructure\AppProd;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppProd\AppProdCaddyManager;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Models\Node;

/**
 * The `app-prod` role's Caddy step. Production Route sites render from stored Route state, so the role
 * orders Caddy after the WireGuard interface and requests a Node Caddy build (ADR 0141).
 */
final readonly class RemoteAppProdCaddyManager implements AppProdCaddyManager
{
    public function __construct(
        private NodeCaddyBuilds $builds,
        private AgentCommandRunner $agents,
    ) {}

    public function converge(Node $node): void
    {
        AgentStep::run(
            $this->agents,
            $node,
            'caddy.order-after-vpn',
            [],
            step: 'app-prod-caddy-service-ordering',
            errorCode: 'app-prod.caddy_config_failed',
            label: 'App production',
        );
        $this->build($node);
    }

    public function remove(Node $node): void
    {
        $this->build($node);
    }

    private function build(Node $node): void
    {
        try {
            $this->builds->build($node);
        } catch (NodeCaddyBuildException $exception) {
            throw new RuntimeConvergenceException(
                step: 'app-prod-caddy-config',
                errorCode: 'app-prod.caddy_config_failed',
                message: $exception->getMessage(),
                previous: $exception,
                result: $exception->result(),
            );
        }
    }
}
