<?php

declare(strict_types=1);

namespace App\Domain\ProxyCli;

use App\Models\DatabaseConnection;
use LogicException;

final readonly class ProxyCliCollectorEnvironment
{
    public function __construct(private ProxyCliState $state) {}

    /** @return array<string, string> */
    public function build(DatabaseConnection $connection): array
    {
        return [
            'PROXYCLI_CLIPROXY_URL' => $this->state->cliproxyUrl() ?? throw new LogicException('ProxyCli setup has no router URL.'),
            'PROXYCLI_MANAGEMENT_KEY' => $this->state->cliproxyManagementKey() ?? throw new LogicException('ProxyCli setup has no management key.'),
            'PROXYCLI_READ_TOKEN' => $this->state->readToken() ?? throw new LogicException('ProxyCli setup has no read token.'),
            'PROXYCLI_CONTROL_TOKEN' => $this->state->controlToken() ?? throw new LogicException('ProxyCli setup has no control token.'),
            'PROXYCLI_CACHE_HOST' => (string) $connection->host,
            'PROXYCLI_CACHE_PORT' => (string) $connection->port,
            'PROXYCLI_CACHE_USERNAME' => $connection->username ?? '',
            'PROXYCLI_CACHE_PASSWORD' => $connection->password ?? '',
            'PROXYCLI_PORT' => (string) ProxyCliProcess::PORT,
        ];
    }
}
