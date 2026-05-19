<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Http\Gateway\GatewayApiException;
use App\Models\DatabaseConnection;
use App\Services\DatabaseConnections\DatabaseConnectionRegistryFailure;
use App\Services\DatabaseConnections\DatabaseConnectionSelector;
use App\Services\DatabaseConnections\DatabaseQueryRunnerFailure;
use Throwable;

use function Laravel\Prompts\table;

trait RunsDatabaseConnectionCommands
{
    use InteractsWithDatabaseRegistry;

    private function resolveDatabaseConnection(DatabaseConnectionSelector $selector): DatabaseConnection|DatabaseConnectionRegistryFailure
    {
        $target = $this->stringArgument('target');

        if ($target === null) {
            return DatabaseConnectionRegistryFailure::validation('target', null, 'Target is required.');
        }

        return $selector->resolve($target, $this->stringOption('connection'));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    private function respondDatabaseSuccess(array $data, array $meta, ?DatabaseConnection $connection = null, bool $forceJson = false): int
    {
        if ($connection instanceof DatabaseConnection) {
            $meta = [
                'connection' => $connection->slug,
                'driver' => $connection->driver,
                ...$meta,
            ];
        }

        if ($forceJson) {
            $this->line(json_encode([
                'success' => [
                    'data' => $data,
                    'meta' => $meta === [] ? (object) [] : $meta,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if ($this->wantsJson()) {
            return $this->respondSuccess($data, $meta);
        }

        if (array_key_exists('affected_rows', $data)) {
            $this->line(sprintf('Affected rows: %s', $data['affected_rows']));

            return self::SUCCESS;
        }

        $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
        $columns = is_array($data['columns'] ?? null) ? $data['columns'] : array_keys($rows[0] ?? []);

        if ($rows === []) {
            $this->line('No rows.');

            return self::SUCCESS;
        }

        table($columns, array_map(
            fn (array $row): array => array_map(fn (string $column): mixed => $row[$column] ?? null, $columns),
            $rows,
        ));

        return self::SUCCESS;
    }

    private function respondDatabaseFailure(Throwable|DatabaseConnectionRegistryFailure|GatewayApiException $failure, bool $forceJson = false): int
    {
        if ($failure instanceof DatabaseConnectionRegistryFailure) {
            if ($forceJson) {
                $this->line(json_encode([
                    'error' => [
                        'code' => $failure->code,
                        'message' => $failure->message,
                        'meta' => $failure->meta === [] ? (object) [] : $failure->meta,
                    ],
                ], JSON_THROW_ON_ERROR));

                return self::FAILURE;
            }

            return $this->respondFailure($failure->code, $failure->message, $failure->meta);
        }

        if ($failure instanceof DatabaseQueryRunnerFailure) {
            if ($forceJson) {
                $this->line(json_encode([
                    'error' => [
                        'code' => $failure->errorCode,
                        'message' => $failure->getMessage(),
                        'meta' => $failure->meta === [] ? (object) [] : $failure->meta,
                    ],
                ], JSON_THROW_ON_ERROR));

                return self::FAILURE;
            }

            return $this->respondFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        }

        if ($failure instanceof GatewayApiException) {
            if ($forceJson) {
                $this->line(json_encode([
                    'error' => [
                        'code' => $failure->errorCode() ?? 'gateway_unavailable',
                        'message' => $failure->getMessage(),
                        'meta' => $failure->errorMeta() === [] ? (object) [] : $failure->errorMeta(),
                    ],
                ], JSON_THROW_ON_ERROR));

                return self::FAILURE;
            }

            return $this->respondFailure(
                $failure->errorCode() ?? 'gateway_unavailable',
                $failure->getMessage(),
                $failure->errorMeta(),
            );
        }

        if ($forceJson) {
            $this->line(json_encode([
                'error' => [
                    'code' => 'database_query.failed',
                    'message' => 'Database operation failed.',
                    'meta' => (object) [],
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        return $this->respondFailure('database_query.failed', 'Database operation failed.', []);
    }
}
