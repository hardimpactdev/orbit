<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

use App\Models\DatabaseConnection;
use LogicException;

final readonly class AiRouterCollectorEnvironment
{
    public function __construct(private AiRouterState $state) {}

    /** @return array<string, string> */
    public function build(DatabaseConnection $connection): array
    {
        return [
            'AI_ROUTER_CLIPROXY_URL' => $this->state->routerUrl() ?? throw new LogicException('AI router setup has no router URL.'),
            'AI_ROUTER_MANAGEMENT_KEY' => $this->state->managementKey() ?? throw new LogicException('AI router setup has no management key.'),
            'AI_ROUTER_READ_TOKEN' => $this->state->readToken() ?? throw new LogicException('AI router setup has no read token.'),
            'AI_ROUTER_CONTROL_TOKEN' => $this->state->controlToken() ?? throw new LogicException('AI router setup has no control token.'),
            'AI_ROUTER_CACHE_HOST' => $connection->host ?? throw new LogicException('AI router cache connection has no host.'),
            'AI_ROUTER_CACHE_PORT' => (string) ($connection->port ?? throw new LogicException('AI router cache connection has no port.')),
            'AI_ROUTER_CACHE_USERNAME' => $connection->username ?? '',
            'AI_ROUTER_CACHE_PASSWORD' => $connection->password ?? '',
        ];
    }
}
