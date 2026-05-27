<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('firewall:deny
    {name? : Firewall rule name}
    {--port= : Destination port or range}
    {--node= : Target node}
    {--direction=incoming : incoming or outgoing}
    {--from= : Source CIDR or any}
    {--to= : Destination CIDR}
    {--protocol=tcp : tcp or udp}
    {--reason= : Operator note}
    {--json : Output JSON}')]
#[Description('Create or update deny firewall rule intent')]
class FirewallDenyCommand extends AbstractFirewallStoreCommand
{
    protected function firewallAction(): string
    {
        return 'deny';
    }
}
