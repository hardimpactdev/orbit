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

#[Signature('database:schema
    {target : App, workspace, or connection slug}
    {--connection= : Connection slug when the target maps to multiple connections}
    {--json : Output JSON}')]
#[Description('Show schema for a registered database connection')]
final class DatabaseSchemaCommand extends Command implements Loggable
{
    use RunsDatabaseConnectionCommands;

    public function handle(DatabaseConnectionSelector $selector, DatabaseConnectionExecutor $executor, DatabaseAuditPayload $audit): int
    {
        return $this->withDatabaseActivity(ActivityLogType::Read, fn (): int => $this->runDatabaseSchema($selector, $executor, $audit));
    }

    private function runDatabaseSchema(DatabaseConnectionSelector $selector, DatabaseConnectionExecutor $executor, DatabaseAuditPayload $audit): int
    {
        try {
            $this->databaseActivityProperties($audit->registry('schema', extra: [
                'target' => $this->stringArgument('target'),
                'selected_connection' => $this->stringOption('connection'),
            ]));

            if (! $this->isGatewayCaller()) {
                $result = $this->sendGatewayRequest(new SchemaDatabaseConnectionRequest(
                    operation: 'schema',
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

            $this->databaseActivitySubject($connection);
            $this->databaseActivityProperties($audit->schema('schema', $connection, (string) $this->argument('target')));

            $result = $executor->schema($connection);
            $this->databaseActivityProperties($audit->schema('schema', $connection, (string) $this->argument('target'), $result['meta']));

            return $this->respondDatabaseSuccess($result['data'], $result['meta'], $connection);
        } catch (Throwable $throwable) {
            return $this->respondDatabaseFailure($throwable);
        }
    }
}
