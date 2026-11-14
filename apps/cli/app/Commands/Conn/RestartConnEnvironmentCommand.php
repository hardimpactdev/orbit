<?php

declare(strict_types=1);

namespace App\Commands\Conn;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\GatedExtensionCommand;
use Orbit\Sdk\Requests\Conn\RestartConnEnvironmentRequest;
use Orbit\Sdk\Responses\Conn\ConnEnvironmentRestartsResponse;

final class RestartConnEnvironmentCommand extends GatewayCommand implements GatedExtensionCommand
{
    use RestartsConnEnvironments;

    #[\Override]
    protected $signature = 'conn:environment-restart
        {instance : Numeric Instance ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = "Restart an idle container Instance's T3 server so that it shows its machine name";

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $instanceId = $this->positiveId('instance', 'Instance', 'instance.id_invalid');
        if ($instanceId === null) {
            return self::FAILURE;
        }
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }
        $response = $this->send($connector, new RestartConnEnvironmentRequest($instanceId), ConnEnvironmentRestartsResponse::class);

        return $response instanceof ConnEnvironmentRestartsResponse ? $this->writeRestarts($connector, $response) : self::FAILURE;
    }
}
