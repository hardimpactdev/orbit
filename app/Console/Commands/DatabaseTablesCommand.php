<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsDatabaseConnectionCommands;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\Requests\Database\SchemaDatabaseConnectionRequest;
use App\Models\DatabaseConnection;
use App\Services\DatabaseConnections\DatabaseConnectionExecutor;
use App\Services\DatabaseConnections\DatabaseConnectionSelector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('database:tables
    {target : App, workspace, or connection slug}
    {--connection= : Connection slug when the target maps to multiple connections}
    {--json : Output JSON}')]
#[Description('List tables for a registered database connection')]
final class DatabaseTablesCommand extends Command
{
    use RunsDatabaseConnectionCommands;

    public function handle(DatabaseConnectionSelector $selector, DatabaseConnectionExecutor $executor): int
    {
        return $this->runSchemaOperation('tables', $selector, $executor);
    }

    private function runSchemaOperation(string $operation, DatabaseConnectionSelector $selector, DatabaseConnectionExecutor $executor): int
    {
        try {
            if (! $this->isGatewayCaller()) {
                $result = $this->sendGatewayRequest(new SchemaDatabaseConnectionRequest(
                    operation: $operation,
                    target: (string) $this->argument('target'),
                    connection: $this->stringOption('connection'),
                ));

                if ($result instanceof GatewayApiException) {
                    return $this->respondDatabaseFailure($result);
                }

                return $this->respondDatabaseSuccess($result['result']['data'] ?? [], $result['result']['meta'] ?? []);
            }

            $connection = $this->resolveDatabaseConnection($selector);

            if (! $connection instanceof DatabaseConnection) {
                return $this->respondDatabaseFailure($connection);
            }

            $result = $executor->tables($connection);

            return $this->respondDatabaseSuccess($result['data'], $result['meta'], $connection);
        } catch (Throwable $throwable) {
            return $this->respondDatabaseFailure($throwable);
        }
    }
}
