<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\DatabaseConnections\ShowDatabaseSchemaRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class ShowDatabaseSchemaCommand extends DatabaseCommand
{
    use FollowsDatabaseInspections;

    #[\Override]
    protected $signature = 'database:schema
        {slug : Database connection slug}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show schema for a registered Database connection.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $slug = $this->slug();

        if ($slug === null) {
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
        $operation = $this->send($connector, new ShowDatabaseSchemaRequest($slug), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followInspection($connector, $profile, $operation, $slug)
            : self::FAILURE;
    }
}
