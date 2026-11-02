<?php

declare(strict_types=1);

namespace App\Commands\Firewall;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\GatewayRequest;

abstract class StoreFirewallRuleCommand extends FirewallCommand
{
    abstract protected function request(int $nodeId, string $port, ?string $source, ?string $protocol): GatewayRequest;

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $target = $this->target();
        $source = $target === null ? false : $this->source();

        if ($target === null || $source === false) {
            return self::FAILURE;
        }

        [$nodeId, $port, $protocol] = $target;

        return $this->admit($repository, $connectors, $this->request($nodeId, $port, $source, $protocol),
            "Apply firewall rule [{$this->describe($port, $protocol, $source)}]", 'Firewall rule');
    }
}
