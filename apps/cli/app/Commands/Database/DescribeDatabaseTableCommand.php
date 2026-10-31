<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\DatabaseConnections\DescribeDatabaseTableRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class DescribeDatabaseTableCommand extends DatabaseCommand
{
    use FollowsDatabaseInspections;

    #[\Override]
    protected $signature = 'database:describe
        {slug : Database connection slug}
        {table : Table name}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Describe one table on a registered Database connection.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $slug = $this->slug();
        $table = $this->stringArgument('table', 'Table name', 'database.table_required');

        if ($slug === null || $table === null) {
            return self::FAILURE;
        }

        if (strlen($table) > 64 || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $table) !== 1) {
            $this->renderGatewayFailure('database.table_invalid', 'Table name must be a bounded SQL identifier.');

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
        $operation = $this->send($connector, new DescribeDatabaseTableRequest($slug, $table), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followInspection($connector, $profile, $operation, $slug)
            : self::FAILURE;
    }
}
