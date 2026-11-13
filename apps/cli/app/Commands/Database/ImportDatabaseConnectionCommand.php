<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\DatabaseConnections\ImportDatabaseConnectionRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class ImportDatabaseConnectionCommand extends DatabaseCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'database:import
        {slug : Database connection slug}
        {--file= : Absolute path of the .sql or .sql.gz dump on the server\'s Node}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Load a SQL dump into an empty database on a Database server, as its owner.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $slug = $this->slug();
        $file = $this->option('file');
        if (! is_string($file) || $file === '') {
            $this->renderGatewayFailure('database.import_file_required', 'Pass the dump with --file=PATH.');

            return self::FAILURE;
        }
        if ($slug === null) {
            return self::FAILURE;
        }
        $connector = $this->gatewayConnector($repository, $connectors);
        $profile = $connector === null ? null : $this->activeGatewayProfile($repository);
        if ($connector === null || $profile === null) {
            return self::FAILURE;
        }
        $operation = $this->send($connector, new ImportDatabaseConnectionRequest($slug, $file,
            is_string($this->option('idempotency-key')) ? $this->option('idempotency-key') : null), OperationResponse::class);
        if (! $operation instanceof OperationResponse) {
            return self::FAILURE;
        }

        return $this->followOperation($connector, $profile, $operation, "Import Database dump [{$slug}]", 'Database import');
    }
}
