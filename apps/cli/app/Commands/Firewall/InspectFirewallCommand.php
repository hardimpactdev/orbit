<?php

declare(strict_types=1);

namespace App\Commands\Firewall;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Firewall\InspectFirewallRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class InspectFirewallCommand extends FirewallCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'firewall:inspect
        {--node= : Numeric target Node ID}
        {--idempotency-key= : Reuse a key to retry this inspection start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Refresh one Node firewall snapshot through native Swamp inspection.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $nodeId = $this->nodeId();
        if ($nodeId === null) {
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
        $key = $this->option('idempotency-key');
        $operation = $this->send($connector, new InspectFirewallRequest($nodeId, is_string($key) ? $key : null), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, "Inspect firewall on Node #{$nodeId}", 'Firewall inspection')
            : self::FAILURE;
    }
}
