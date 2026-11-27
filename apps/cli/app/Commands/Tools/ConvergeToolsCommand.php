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
    protected $signature = 'tool:converge {--node= : Numeric target Node ID} {--tool-id=* : Reconcile only the named Tool IDs} {--idempotency-key= : Reuse a stable operation start key} {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Reconcile only registered optional Tools on a managed Linux or macOS Node.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $node = $this->nodeId();
        $ids = [];
        foreach ((array) $this->option('tool-id') as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (! is_int($id)) {
                $this->renderGatewayFailure('tool.scope_invalid', 'Each --tool-id must be a positive integer.');

                return self::FAILURE;
            }
            $ids[] = $id;
        }
        if ($node === null || ($connector = $this->gatewayConnector($repository, $connectors)) === null) {
            return self::FAILURE;
        }
        $profile = $this->activeGatewayProfile($repository);
        if ($profile === null) {
            return self::FAILURE;
        }
        $operation = $this->send($connector, new ConvergeToolsRequest($node, $this->stringOption('idempotency-key'), $ids === [] ? null : $ids), OperationResponse::class);

        return $operation instanceof OperationResponse ? $this->followOperation($connector, $profile, $operation, "Converge Tools on Node [{$node}]", 'Tools') : self::FAILURE;
    }
}
