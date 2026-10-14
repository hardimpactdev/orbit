<?php

declare(strict_types=1);

namespace App\Actions\ProxyCli;

use App\Domain\ProxyCli\ProxyCliPlacement;
use App\Domain\ProxyCli\ProxyCliRuntimeLifecycle;
use App\Domain\ProxyCli\ProxyCliState;
use App\Models\DatabaseConnection;

final readonly class ReconvergeProxyCliConnectionAction
{
    public function __construct(
        private ProxyCliState $state,
        private ProxyCliPlacement $placement,
        private ProxyCliRuntimeLifecycle $runtime,
    ) {}

    public function execute(DatabaseConnection $connection): void
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
        $this->runtime->converge($node, [
            'PROXYCLI_CLIPROXY_URL' => $this->state->cliproxyUrl() ?? '',
            'PROXYCLI_MANAGEMENT_KEY' => $this->state->cliproxyManagementKey() ?? '',
            'PROXYCLI_READ_TOKEN' => $this->state->readToken() ?? '',
            'PROXYCLI_CONTROL_TOKEN' => $this->state->controlToken() ?? '',
            'PROXYCLI_CACHE_HOST' => $connection->host ?: '127.0.0.1',
            'PROXYCLI_CACHE_PORT' => (string) ($connection->port ?? 6379),
            'PROXYCLI_CACHE_USERNAME' => $connection->username ?? '',
            'PROXYCLI_CACHE_PASSWORD' => $connection->password ?? '',
            'PROXYCLI_PORT' => (string) $this->state->processPort(),
        ], $this->state->processPort());
    }
}
