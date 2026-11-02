<?php

declare(strict_types=1);

namespace App\Commands\Firewall;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Firewall\RemoveFirewallRuleRequest;

final class RemoveFirewallRuleCommand extends FirewallCommand
{
    #[\Override]
    protected $signature = 'firewall:remove
        {port : Destination port or ordered range of the rule}
        {--node= : Numeric target node ID}
        {--from= : Optional source that the rule must have}
        {--protocol= : Optional tcp or udp protocol that the rule must have}
        {--yes : Confirm firewall rule removal}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove the one firewall rule that matches a port through the gateway.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $target = $this->target();
        $source = $target === null ? false : $this->source();

        if ($target === null || $source === false) {
            return self::FAILURE;
        }

        [$nodeId, $port, $protocol] = $target;
        $rule = $this->describe($port, $protocol, $source);

        if (! $this->confirmAction("Remove firewall rule [{$rule}] from Node #{$nodeId}?", 'Firewall rule removal cancelled.')) {
            return self::FAILURE;
        }

        return $this->admit($repository, $connectors,
            new RemoveFirewallRuleRequest($nodeId, $port, $source, $protocol, $this->stringOption('idempotency-key')),
            "Remove firewall rule [{$rule}]", 'Firewall rule removal');
    }
}
