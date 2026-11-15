<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks\Pi;

use App\Domain\Tasks\AgentDriverException;
use App\Infrastructure\Shared\StoredValue;
use App\Models\Node;

/** Resolves a task VM's Pi server address. */
final readonly class PiConnection
{
    public function baseUrl(Node $node): string
    {
        $host = $node->wireguard_ip;
        if (! is_string($host) || filter_var($host, FILTER_VALIDATE_IP) === false) {
            throw new AgentDriverException('The agent Node address is unavailable.');
        }
        $host = str_contains($host, ':') ? '['.$host.']' : $host;

        return 'http://'.$host.':'.$this->port();
    }

    /** The port of every managed Pi server, `ORBIT_PI_PORT`. */
    public function port(): int
    {
        $port = StoredValue::integer(config('orbit.pi.port', 3774), 3774);

        return $port > 0 && $port <= 65535 ? $port : 3774;
    }
}
