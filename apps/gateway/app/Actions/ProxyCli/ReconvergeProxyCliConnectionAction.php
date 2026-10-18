<?php

declare(strict_types=1);

namespace App\Actions\ProxyCli;

use App\Domain\DatabaseConnections\DatabaseConnectionUpdateHook;
use App\Domain\ProxyCli\ProxyCliCollectorEnvironment;
use App\Domain\ProxyCli\ProxyCliPlacement;
use App\Domain\ProxyCli\ProxyCliRuntimeLifecycle;
use App\Domain\ProxyCli\ProxyCliState;
use App\Models\DatabaseConnection;

final readonly class ReconvergeProxyCliConnectionAction implements DatabaseConnectionUpdateHook
{
    public function __construct(
        private ProxyCliState $state,
        private ProxyCliPlacement $placement,
        private ProxyCliRuntimeLifecycle $runtime,
        private ProxyCliCollectorEnvironment $environment,
    ) {}

    public function validate(DatabaseConnection $connection): void
    {
        if (! $this->state->enabled() || $this->state->cacheConnection() !== $connection->slug) {
            return;
        }
        $nodeId = $this->state->nodeId();
        if ($nodeId === null) {
            return;
        }
        $this->placement->node($nodeId);
        $this->placement->validateConnection($connection);
    }

    public function afterCommit(DatabaseConnection $connection): void
    {
        if (! $this->state->enabled() || $this->state->cacheConnection() !== $connection->slug) {
            return;
        }
        $nodeId = $this->state->nodeId();
        if ($nodeId === null) {
            return;
        }
        $node = $this->placement->node($nodeId);
        $connection = $this->placement->connection($connection->slug);
        $this->runtime->converge($node, $this->environment->build($connection), $this->state->processPort());
    }
}
