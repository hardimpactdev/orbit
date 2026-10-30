<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Nodes\RemoveNodeRequest;
use Orbit\Sdk\Requests\Nodes\ShowNodeRequest;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class RemoveNodeCommand extends NodeCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'node:remove
        {node : Numeric node ID}
        {--force : Skip the destructive confirmation prompt}
        {--offline : Shed roles and remove a node Orbit cannot reach}
        {--idempotency-key= : Reuse a key to retry this Operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove a node registered with the active gateway.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $nodeId = $this->positiveId('node', 'Node', 'node.id_invalid');

        if ($nodeId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $existing = $this->sendWithProgress($connector, new ShowNodeRequest($nodeId), NodeResponse::class, ['Resolve Node', 'Loading Node', 'Loaded Node']);

        if (! $existing instanceof NodeResponse) {
            return self::FAILURE;
        }

        if (! $this->confirmed($existing)) {
            return self::FAILURE;
        }

        $operation = $this->sendWithProgress(
            $connector,
            new RemoveNodeRequest($nodeId, force: true, offline: $this->option('offline') === true,
                idempotencyKey: is_string($this->option('idempotency-key')) ? $this->option('idempotency-key') : null),
            OperationResponse::class,
            ['Remove Node', 'Recording Node removal', 'Node removal Operation recorded'],
        );
        if (! $operation instanceof OperationResponse) {
            return self::FAILURE;
        }
        $profile = $this->activeGatewayProfile($repository);

        return $profile === null ? self::FAILURE
            : $this->followOperation($connector, $profile, $operation, 'Remove Node', 'Node removal');
    }

    private function confirmed(NodeResponse $node): bool
    {
        return $this->confirmAction(
            "Remove Node [{$node->name}] (#{$node->id}) from the Gateway?",
            'Node removal cancelled.',
            option: 'force',
            requiredCode: 'node.confirmation_required',
            requiredMessage: 'Use --force to confirm node removal.',
        );
    }
}
