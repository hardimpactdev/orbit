<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsDatabaseConnectionCommands;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\Requests\Database\QueryDatabaseConnectionRequest;
use App\Models\DatabaseConnection;
use App\Services\DatabaseConnections\DatabaseAuditPayload;
use App\Services\DatabaseConnections\DatabaseConnectionExecutor;
use App\Services\DatabaseConnections\DatabaseConnectionSelector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('database:query
    {target : App, workspace, or connection slug}
    {--sql= : SQL statement to execute}
    {--connection= : Connection slug when the target maps to multiple connections}
    {--write : Allow write statements}
    {--limit= : Maximum rows for read queries}
    {--full : Allow larger read result limits}
    {--timeout= : Query timeout in seconds}
    {--max-json-bytes= : Maximum JSON result size before row truncation}
    {--json : Output JSON}')]
#[Description('Execute a database query against a registered connection')]
final class DatabaseQueryCommand extends Command implements Loggable
{
    use RunsDatabaseConnectionCommands;

    public function handle(DatabaseConnectionSelector $selector, DatabaseConnectionExecutor $executor, DatabaseAuditPayload $audit): int
    {
        return $this->withDatabaseActivity(ActivityLogType::Read, fn (): int => $this->runDatabaseQuery($selector, $executor, $audit));
    }

    private function runDatabaseQuery(DatabaseConnectionSelector $selector, DatabaseConnectionExecutor $executor, DatabaseAuditPayload $audit): int
    {
        try {
            $sql = $this->stringOption('sql');

            if ($sql === null) {
                $this->databaseActivityProperties([
                    'operation' => 'query',
                    'target' => $this->stringArgument('target'),
                ]);

                return $this->respondFailure('validation_failed', 'SQL is required.', ['field' => 'sql']);
            }

            $options = $this->queryOptions();

            if (! $this->isGatewayCaller()) {
                $result = $this->sendGatewayRequest(new QueryDatabaseConnectionRequest(
                    target: (string) $this->argument('target'),
                    sql: $sql,
                    connection: $this->stringOption('connection'),
                    options: $options,
                ));

                if ($result instanceof GatewayApiException) {
                    return $this->respondDatabaseFailure($result, forceJson: true);
                }

                return $this->respondDatabaseSuccess($result['result']['data'] ?? [], $result['result']['meta'] ?? [], forceJson: true);
            }

            $connection = $this->resolveDatabaseConnection($selector);

            if (! $connection instanceof DatabaseConnection) {
                return $this->respondDatabaseFailure($connection, forceJson: true);
            }

            $this->databaseActivitySubject($connection);
            $this->databaseActivityProperties($audit->query($connection, (string) $this->argument('target'), $sql, $options));
            $this->databaseActivityEffect($options['write'] === true ? ActivityLogType::Write : ActivityLogType::Read);

            $result = $executor->query($connection, $sql, $options);
            $this->databaseActivityProperties($audit->query($connection, (string) $this->argument('target'), $sql, $options, $result['meta'], [
                'affected_rows' => $result['data']['affected_rows'] ?? null,
            ]));

            return $this->respondDatabaseSuccess($result['data'], $result['meta'], $connection, forceJson: true);
        } catch (Throwable $throwable) {
            return $this->respondDatabaseFailure($throwable, forceJson: true);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function queryOptions(): array
    {
        return [
            'write' => (bool) $this->option('write'),
            'full' => (bool) $this->option('full'),
            'limit' => $this->stringOption('limit'),
            'timeout' => $this->stringOption('timeout'),
            'max_json_bytes' => $this->stringOption('max-json-bytes'),
        ];
    }
}
