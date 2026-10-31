<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ProgressOutcome;
use App\Support\Console\ProgressState;
use Orbit\Sdk\Requests\Nodes\ConvergeNodeRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class ConvergeNodeCommand extends NodeCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'node:converge
        {node : Node ID or name}
        {--force : Re-apply every artifact, not only the ones whose digest changed}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Re-apply the Gateway-rendered Orbit footprint of a Node. It never changes an Instance or a role.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $nodeId = $this->resolveNodeId($connector, $this->argument('node'));

        if ($nodeId === null) {
            return self::FAILURE;
        }

        $result = $this->sendWithProgress(
            $connector,
            new ConvergeNodeRequest($nodeId, $this->option('force') === true,
                is_string($this->option('idempotency-key')) ? $this->option('idempotency-key') : null),
            OperationResponse::class,
            ['Converge Node footprint', 'Converging Node footprint', 'Node footprint request completed'],
            static fn (OperationResponse $response): ProgressOutcome => new ProgressOutcome(ProgressState::Success,
                'Node footprint Operation recorded'),
        );

        if (! $result instanceof OperationResponse) {
            return self::FAILURE;
        }

        $profile = $this->activeGatewayProfile($repository);

        return $profile === null ? self::FAILURE
            : $this->followOperation($connector, $profile, $result, 'Converge Node footprint', 'Node footprint');
    }
}
