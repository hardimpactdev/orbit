<?php

declare(strict_types=1);

namespace App\Commands\Environment;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Environment\ImportInstanceEnvironmentRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class ImportEnvironmentCommand extends EnvironmentCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'env:import
        {--instance= : Positive Instance ID or exact Route domain}
        {--replace : Replace stored-key conflicts while retaining other stored keys}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Import the workload environment file into stored Instance configuration.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $selector = $this->instanceSelector();

        if ($selector === null) {
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

        $operation = $this->send(
            $connector,
            new ImportInstanceEnvironmentRequest(
                instance: $selector,
                replace: $this->option('replace') === true ? true : null,
            ),
            OperationResponse::class,
        );

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, 'Import environment', 'Environment import')
            : self::FAILURE;
    }
}
