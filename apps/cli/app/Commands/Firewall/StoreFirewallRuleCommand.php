<?php

declare(strict_types=1);

namespace App\Commands\Firewall;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

abstract class StoreFirewallRuleCommand extends FirewallCommand
{
    use FollowsOperations;

    abstract protected function request(
        int $nodeId,
        string $name,
        ?string $source,
        ?string $protocol,
        string $port,
    ): GatewayRequest;

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $nodeId = $this->nodeId();

        if ($nodeId === null) {
            return self::FAILURE;
        }

        $name = $this->ruleName();

        if ($name === null) {
            return self::FAILURE;
        }

        $protocol = $this->protocol();

        if ($protocol === false) {
            return self::FAILURE;
        }

        $port = $this->port();

        if ($port === null) {
            return self::FAILURE;
        }

        $source = $this->source();

        if ($source === false) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $profile = $this->activeGatewayProfile($repository);
        if ($profile === null) {
            return self::FAILURE;
        }
        $operation = $this->send($connector, $this->request($nodeId, $name, $source, $protocol, $port), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, "Apply firewall rule [{$name}]", 'Firewall rule')
            : self::FAILURE;
    }
}
