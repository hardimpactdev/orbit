<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentCommands;

use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;

/** Picks the transport of a Node: a local process on the Gateway's own Node, SSH everywhere else. */
class AgentTransports
{
    public function __construct(
        private readonly NodeAccessAuthorizer $access,
        private readonly ProcessRunner $processes,
        private readonly SshExecutor $ssh,
        private readonly SshKeyProvider $keys,
        private readonly KnownHostsStore $knownHosts,
        private readonly string $orbitHome,
    ) {}

    public function for(Node $node): AgentTransport
    {
        if ($this->access->isGatewayNode($node)) {
            return new LocalAgentTransport($this->processes, $this->orbitHome);
        }

        return new SshAgentTransport($node, $this->ssh, $this->keys, $this->knownHosts);
    }
}
