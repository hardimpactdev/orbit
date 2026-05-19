<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsDatabaseConnectionCommands;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\Requests\Database\SchemaDatabaseConnectionRequest;
use App\Models\DatabaseConnection;
use App\Services\DatabaseConnections\DatabaseAuditPayload;
use App\Services\DatabaseConnections\DatabaseConnectionExecutor;
use App\Services\DatabaseConnections\DatabaseConnectionSelector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('database:describe
    {target : App, workspace, or connection slug}
    {table : Table name}
    {--connection= : Connection slug when the target maps to multiple connections}
    {--json : Output JSON}')]
#[Description('Describe a table for a registered database connection')]
final class DatabaseDescribeCommand extends Command implements Loggable
{
    use RunsDatabaseConnectionCommands;

    public function handle(DatabaseConnectionSelector $selector, DatabaseConnectionExecutor $executor, DatabaseAuditPayload $audit): int
    {
        return $this->withDatabaseActivity(ActivityLogType::Read, fn (): int => $this->runDatabaseDescribe($selector, $executor, $audit));
    }

    private function runDatabaseDescribe(DatabaseConnectionSelector $selector, DatabaseConnectionExecutor $executor, DatabaseAuditPayload $audit): int
    {
        try {
            $table = $this->stringArgument('table');

            if ($table === null) {
                return $this->respondFailure('validation_failed', 'Table is required.', ['field' => 'table']);
            }

            if (! $this->isGatewayCaller()) {
                $result = $this->sendGatewayRequest(new SchemaDatabaseConnectionRequest(
                    operation: 'describe',
                    target: (string) $this->argument('target'),
                    connection: $this->stringOption('connection'),
                    table: $table,
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

            $this->databaseActivitySubject($connection);
            $this->databaseActivityProperties($audit->schema('describe', $connection, (string) $this->argument('target'), table: $table));

            $result = $executor->describe($connection, $table);
            $this->databaseActivityProperties($audit->schema('describe', $connection, (string) $this->argument('target'), $result['meta'], $table));

            return $this->respondDatabaseSuccess($result['data'], $result['meta'], $connection);
        } catch (Throwable $throwable) {
            return $this->respondDatabaseFailure($throwable);
        }
    }
}
