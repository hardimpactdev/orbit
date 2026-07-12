<?php

declare(strict_types=1);

namespace App\Commands\Firewall;

final class FirewallDenyCommand extends FirewallStoreCommand
{
    // @orbit-ssh-lane transitional-ssh
    #[\Override]
    protected $signature = 'firewall:deny
        {name? : Firewall rule name}
        {--port= : Destination port or range}
        {--node= : Target node}
        {--node-transport= : Node command transport preference (auto|agent-push|transitional-ssh-fallback)}
        {--direction=incoming : incoming or outgoing}
        {--from= : Source CIDR or any}
        {--to= : Destination CIDR}
        {--protocol=tcp : tcp or udp}
        {--reason= : Operator note}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Create or update deny firewall rule intent through the gateway.';

    protected function firewallAction(): string
    {
        return 'deny';
    }

    protected function treeTitle(): string
    {
        return 'Denying Firewall Rule';
    }
}
