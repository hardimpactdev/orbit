<?php

declare(strict_types=1);

namespace App\Commands\Firewall;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Firewall\AllowlistFirewallRuleRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class AllowlistFirewallRuleCommand extends FirewallCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'firewall:allowlist
        {name : Stable firewall rule name}
        {--node= : Numeric target node ID}
        {--from=* : Allowed source IPv4 address or CIDR, repeatable}
        {--protocol= : Optional tcp or udp protocol}
        {--port= : Destination port or ordered range}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Replace the sources allowed to reach one Node port before WireGuard trust.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $nodeId = $this->nodeId();
        $name = $this->ruleName();
        $protocol = $this->protocol();
        $port = $this->port();
        if ($nodeId === null || $name === null || $protocol === false || $port === null) {
            return self::FAILURE;
        }
        $sources = $this->option('from');
        if ($sources === []) {
            $this->renderGatewayFailure('firewall.source_invalid', 'Supply at least one IPv4 source with --from.');

            return self::FAILURE;
        }
        $values = [];
        foreach ($sources as $source) {
            if (! is_string($source)) {
                return self::FAILURE;
            }
            $values[] = $source;
        }
        $connector = $this->gatewayConnector($repository, $connectors);
        $profile = $this->activeGatewayProfile($repository);
        if ($connector === null || $profile === null) {
            return self::FAILURE;
        }
        $response = $this->send($connector, new AllowlistFirewallRuleRequest(
            $nodeId, $name, $values, $protocol, $port,
            is_string($this->option('idempotency-key')) ? $this->option('idempotency-key') : null,
        ), OperationResponse::class);

        return $response instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $response, "Apply firewall allowlist [{$name}]", 'Firewall allowlist')
            : self::FAILURE;
    }
}
