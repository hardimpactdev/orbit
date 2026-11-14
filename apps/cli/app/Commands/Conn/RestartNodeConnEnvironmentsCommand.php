<?php

declare(strict_types=1);

namespace App\Commands\Conn;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\GatedExtensionCommand;
use Orbit\Sdk\Requests\Conn\RestartNodeConnEnvironmentsRequest;
use Orbit\Sdk\Responses\Conn\ConnEnvironmentRestartsResponse;

final class RestartNodeConnEnvironmentsCommand extends GatewayCommand implements GatedExtensionCommand
{
    use RestartsConnEnvironments;

    #[\Override]
    protected $signature = 'conn:node-environments-restart
        {node : Numeric Node ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Restart the idle T3 server of each container Instance on one Node that does not show its machine name yet';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $nodeId = $this->positiveId('node', 'Node', 'node.id_invalid');
        if ($nodeId === null) {
            return self::FAILURE;
        }
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }
        $response = $this->send($connector, new RestartNodeConnEnvironmentsRequest($nodeId), ConnEnvironmentRestartsResponse::class);

        return $response instanceof ConnEnvironmentRestartsResponse ? $this->writeRestarts($connector, $response) : self::FAILURE;
    }
}
