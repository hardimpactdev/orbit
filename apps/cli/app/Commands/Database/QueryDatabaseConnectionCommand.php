<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\DatabaseConnections\QueryDatabaseConnectionRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class QueryDatabaseConnectionCommand extends DatabaseCommand
{
    use FollowsDatabaseInspections;

    #[\Override]
    protected $signature = 'database:query
        {slug : Database connection slug}
        {sql : SQL statement}
        {--write : Allow a write statement}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Run SQL against a registered Database connection.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $slug = $this->slug();
        $sql = $this->stringArgument('sql', 'SQL statement', 'database.sql_required');

        if ($slug === null || $sql === null) {
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
        $operation = $this->send($connector, new QueryDatabaseConnectionRequest($slug, $sql, $this->option('write') === true,
            is_string($this->option('idempotency-key')) ? $this->option('idempotency-key') : null), OperationResponse::class);
        if (! $operation instanceof OperationResponse) {
            return self::FAILURE;
        }

        return $this->option('write') === true
            ? $this->followOperation($connector, $profile, $operation, "Run Database statement [{$slug}]", 'Database statement')
            : $this->followInspection($connector, $profile, $operation, $slug);
    }
}
