<?php

declare(strict_types=1);

namespace App\Services\Database;

use InvalidArgumentException;
use JsonException;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

final readonly class LocalDatabaseQueryAction
{
    private const int DEFAULT_LIMIT = 50;

    private const int MAX_LIMIT = 500;

    private const int MAX_FULL_LIMIT = 10000;

    private const int DEFAULT_TIMEOUT_SECONDS = 10;

    private const int DEFAULT_MAX_JSON_BYTES = 1048576;

    public function __construct(
        private DatabaseQueryClassifier $classifier,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function run(array $payload): array
    {
        $connection = $this->connectionPayload($payload['connection'] ?? null);
        $sql = $this->sql($payload['sql'] ?? null);
        $write = (bool) ($payload['write'] ?? false);
        $full = (bool) ($payload['full'] ?? false);
        $limit = $this->limit($payload['limit'] ?? null, $full);
        $timeout = $this->positiveInteger($payload['timeout'] ?? null, self::DEFAULT_TIMEOUT_SECONDS);
        $maxJsonBytes = $this->positiveInteger($payload['max_json_bytes'] ?? null, self::DEFAULT_MAX_JSON_BYTES);
        $classification = $this->classifier->classify($sql);

        if ($classification->requiresWriteMode && ! $write) {
            throw new LocalDatabaseQueryFailure(
                errorCode: 'database_query.write_not_allowed',
                message: 'This SQL statement requires explicit write mode.',
                meta: ['mode' => $classification->mode],
            );
        }

        $startedAt = microtime(true);

        try {
            $database = $this->openSqliteDatabase($connection, $timeout);

            if ($classification->mode === 'write') {
                return $this->runWriteQuery($database, $sql, $startedAt);
            }

            return $this->runReadQuery($database, $sql, $limit, $maxJsonBytes, $startedAt);
        } catch (LocalDatabaseQueryFailure $failure) {
            throw $failure;
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            throw new LocalDatabaseQueryFailure(
                errorCode: 'database_query.execution_failed',
                message: 'Database query execution failed.',
                meta: ['mode' => $classification->mode],
                previous: $throwable,
            );
        }
    }

    private function runWriteQuery(PDO $database, string $sql, float $startedAt): array
    {
        $statement = $database->prepare($sql);
        $statement->execute();

        return [
            'data' => ['affected_rows' => $statement->rowCount()],
            'meta' => [
                'mode' => 'write',
                'duration_ms' => $this->durationMs($startedAt),
            ],
        ];
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function runReadQuery(PDO $database, string $sql, int $limit, int $maxJsonBytes, float $startedAt): array
    {
        $statement = $database->prepare($sql);
        $statement->execute();
        $columns = $this->columns($statement);
        $rows = [];
        $truncatedByLimit = false;

        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (count($rows) >= $limit) {
                $truncatedByLimit = true;

                break;
            }

            $rows[] = $row;
        }

        $statement->closeCursor();
        $encoded = $this->compactRowsForJsonSize($rows, $columns, $maxJsonBytes);

        return [
            'data' => [
                'columns' => $encoded['columns'],
                'rows' => $encoded['rows'],
            ],
            'meta' => [
                'mode' => 'read',
                'limit' => $limit,
                'total_rows' => $truncatedByLimit ? null : count($rows),
                'returned_rows' => count($encoded['rows']),
                'truncated' => $truncatedByLimit || $encoded['truncated'],
                'truncated_by' => array_values(array_filter([
                    $truncatedByLimit ? 'limit' : null,
                    $encoded['truncated'] ? 'json_size' : null,
                ])),
                'max_json_bytes' => $maxJsonBytes,
                'duration_ms' => $this->durationMs($startedAt),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionPayload(mixed $connection): array
    {
        if (! is_array($connection)) {
            throw new InvalidArgumentException('Connection payload is required.');
        }

        $driver = $connection['driver'] ?? null;

        if ($driver !== 'sqlite') {
            throw new InvalidArgumentException('Only sqlite connections are supported by this internal command.');
        }

        return $connection;
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private function openSqliteDatabase(array $connection, int $timeout): PDO
    {
        $path = $connection['path'] ?? $connection['database'] ?? null;

        if (! is_string($path) || trim($path) === '') {
            throw new InvalidArgumentException('SQLite database path is required.');
        }

        $database = new PDO("sqlite:{$path}");
        $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $database->exec('pragma foreign_keys = on');
        $database->exec('pragma busy_timeout = '.($timeout * 1000));

        return $database;
    }

    private function sql(mixed $sql): string
    {
        if (! is_string($sql) || trim($sql) === '') {
            throw new InvalidArgumentException('SQL is required.');
        }

        return $sql;
    }

    private function limit(mixed $limit, bool $full): int
    {
        $limit = $this->positiveInteger($limit, self::DEFAULT_LIMIT);
        $maximum = $full ? self::MAX_FULL_LIMIT : self::MAX_LIMIT;

        return min($limit, $maximum);
    }

    private function positiveInteger(mixed $value, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (! is_numeric($value) || (int) $value < 1) {
            return $default;
        }

        return (int) $value;
    }

    /**
     * @return list<string>
     */
    private function columns(PDOStatement $statement): array
    {
        $columns = [];

        for ($index = 0; $index < $statement->columnCount(); $index++) {
            $metadata = $statement->getColumnMeta($index);
            $name = is_array($metadata) ? $metadata['name'] ?? null : null;

            if (is_string($name) && $name !== '') {
                $columns[] = $name;
            }
        }

        return $columns;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $columns
     * @return array{columns: list<string>, rows: list<array<string, mixed>>, truncated: bool}
     */
    private function compactRowsForJsonSize(array $rows, array $columns, int $maxJsonBytes): array
    {
        $truncated = false;

        while ($rows !== [] && $this->jsonBytes(['columns' => $columns, 'rows' => $rows]) > $maxJsonBytes) {
            array_pop($rows);
            $truncated = true;
        }

        if ($rows === [] && $this->jsonBytes(['columns' => $columns, 'rows' => $rows]) > $maxJsonBytes) {
            throw new RuntimeException('Query result metadata exceeds the JSON size limit.');
        }

        return [
            'columns' => $columns,
            'rows' => $rows,
            'truncated' => $truncated,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function jsonBytes(array $payload): int
    {
        try {
            return strlen(json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (JsonException $exception) {
            throw new RuntimeException($exception->getMessage(), previous: $exception);
        }
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
