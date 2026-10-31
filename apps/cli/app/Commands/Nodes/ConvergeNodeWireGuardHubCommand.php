<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ProgressOutcome;
use App\Support\Console\ProgressState;
use Orbit\Sdk\Requests\Nodes\ConvergeNodeWireGuardHubRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class ConvergeNodeWireGuardHubCommand extends NodeCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'node:wireguard-hub:converge
        {node : Node ID or name}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Publish the current peer inventory on the active WireGuard hub.';

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
            new ConvergeNodeWireGuardHubRequest($nodeId,
                is_string($this->option('idempotency-key')) ? $this->option('idempotency-key') : null),
            OperationResponse::class,
            ['Converge WireGuard hub', 'Converging WireGuard hub', 'WireGuard hub request completed'],
            static fn (OperationResponse $response): ProgressOutcome => new ProgressOutcome(ProgressState::Success,
                'WireGuard hub Operation recorded'),
        );

        if (! $result instanceof OperationResponse) {
            return self::FAILURE;
        }

        $profile = $this->activeGatewayProfile($repository);

        return $profile === null ? self::FAILURE
            : $this->followOperation($connector, $profile, $result, 'Converge WireGuard hub', 'WireGuard hub');
    }
}
