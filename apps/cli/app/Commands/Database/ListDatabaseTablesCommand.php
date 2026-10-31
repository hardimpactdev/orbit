<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\DatabaseConnections\ListDatabaseTablesRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class ListDatabaseTablesCommand extends DatabaseCommand
{
    use FollowsDatabaseInspections;

    #[\Override]
    protected $signature = 'database:tables
        {slug : Database connection slug}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List tables on a registered Database connection.';

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
        $operation = $this->send($connector, new ListDatabaseTablesRequest($slug), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followInspection($connector, $profile, $operation, $slug)
            : self::FAILURE;
    }
}
