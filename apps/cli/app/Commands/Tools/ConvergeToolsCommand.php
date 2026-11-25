<?php

declare(strict_types=1);

namespace App\Commands\Tools;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Tools\ConvergeToolsRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class ConvergeToolsCommand extends ToolCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'tool:converge {--node= : Numeric target Node ID} {--idempotency-key= : Reuse a stable operation start key} {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Reconcile only registered optional Tools on a managed Linux or macOS Node.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $node = $this->nodeId();
        if ($node === null || ($connector = $this->gatewayConnector($repository, $connectors)) === null) {
            return self::FAILURE;
        }
        $profile = $this->activeGatewayProfile($repository);
        if ($profile === null) {
            return self::FAILURE;
        }
        $operation = $this->send($connector, new ConvergeToolsRequest($node, $this->stringOption('idempotency-key')), OperationResponse::class);

        return $operation instanceof OperationResponse ? $this->followOperation($connector, $profile, $operation, "Converge Tools on Node [{$node}]", 'Tools') : self::FAILURE;
    }
}
