<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('firewall:allow
    {name? : Firewall rule name}
    {--port= : Destination port or range}
    {--node= : Target node}
    {--direction=incoming : incoming or outgoing}
    {--from= : Source CIDR or any}
    {--to= : Destination CIDR}
    {--protocol=tcp : tcp or udp}
    {--reason= : Operator note}
    {--json : Output JSON}')]
#[Description('Create or update allow firewall rule intent')]
class FirewallAllowCommand extends AbstractFirewallStoreCommand
{
    protected function firewallAction(): string
    {
        return 'allow';
    }
}
