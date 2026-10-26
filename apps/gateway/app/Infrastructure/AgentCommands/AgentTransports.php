<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentCommands;

use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\Caddy\Build\CaddySiteRoles;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;

/** Picks the transport of a Node: a local process on the Gateway's own Node, SSH everywhere else. */
class AgentTransports
{
    public function __construct(
        private readonly GatewayServingHost $servingHost,
        private readonly ProcessRunner $processes,
        private readonly SshExecutor $ssh,
        private readonly SshKeyProvider $keys,
        private readonly KnownHostsStore $knownHosts,
        private readonly string $orbitHome,
    ) {}

    public function for(Node $node): AgentTransport
    {
        $serving = $this->servingHost->nodeId();
        $local = $serving !== null
            ? $serving === $node->id
            : CaddySiteRoles::nodeServes($node->id, RoleName::Gateway);

        if ($local) {
            return new LocalAgentTransport($this->processes, $this->orbitHome);
        }

        return new SshAgentTransport($node, $this->ssh, $this->keys, $this->knownHosts);
    }

    public function guest(AgentGuest $guest): AgentTransport
    {
        if ($guest->host !== null) {
            return new IncusAgentTransport($this->for($guest->host), (string) $guest->project, $guest->name);
        }

        return new SshAgentTransport($guest, $this->ssh, $this->keys, $this->knownHosts, $guest->connection);
    }

    /** The transport over a given connection, such as the bootstrap connection of a Node that is being admitted. */
    public function over(Node $node, SshConnection $connection): AgentTransport
    {
        return new SshAgentTransport($node, $this->ssh, $this->keys, $this->knownHosts, $connection);
    }

    public function architectureOver(Node $node, SshConnection $connection): string
    {
        return new SshAgentTransport($node, $this->ssh, $this->keys, $this->knownHosts, $connection)->architecture();
    }
}
