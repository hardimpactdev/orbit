<?php

declare(strict_types=1);

namespace App\Commands\Firewall;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Firewall\DenyFirewallRuleRequest;

final class DenyFirewallRuleCommand extends StoreFirewallRuleCommand
{
    #[\Override]
    protected $signature = 'firewall:deny
        {port : Destination port or ordered range, such as 443 or 8000:8100}
        {--node= : Numeric target node ID}
        {--from= : Optional source IPv4/IPv6 address, CIDR, or any}
        {--protocol= : Optional tcp or udp protocol; both when omitted}
        {--label= : Optional free-text label}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Deny traffic to one Node port through the gateway.';

    protected function request(int $nodeId, string $port, ?string $source, ?string $protocol): GatewayRequest
    {
        return new DenyFirewallRuleRequest($nodeId, $port, $source, $protocol, $this->stringOption('label'), $this->stringOption('idempotency-key'));
    }
}
