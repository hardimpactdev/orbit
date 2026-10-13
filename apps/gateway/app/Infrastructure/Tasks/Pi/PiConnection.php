<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks\Pi;

use App\Domain\Tasks\AgentDriverException;
use App\Domain\TaskVms\TaskVmPlacement;
use App\Infrastructure\Shared\StoredValue;
use App\Models\Node;

/** Resolves a task VM's Pi server address and token. A warm task VM has no token until a group claims it. */
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

    public function token(Node $node): string
    {
        $vm = TaskVmPlacement::forNode($node) ?? throw new AgentDriverException('The Node is no task VM, so it has no Pi server.');

        return $vm->pi_token ?? throw new AgentDriverException('The task VM has no Pi token, because no task group claimed it yet.');
    }

    /**
     * Values that never leave the driver in a transcript: the token, and a task VM's model key.
     *
     * @return list<string>
     */
    public function secrets(Node $node): array
    {
        $vm = TaskVmPlacement::forNode($node);

        return array_values(array_filter([$vm->pi_token ?? '', $vm->model_key ?? ''], static fn (string $value): bool => $value !== ''));
    }
}
