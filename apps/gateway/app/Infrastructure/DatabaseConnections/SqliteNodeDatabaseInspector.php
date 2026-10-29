<?php

declare(strict_types=1);

namespace App\Infrastructure\DatabaseConnections;

use App\Domain\DatabaseConnections\DatabaseQueryResult;
use App\Domain\DatabaseConnections\DatabaseSchemaTable;
use App\Domain\DatabaseConnections\DatabaseTableColumn;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Models\DatabaseConnection;
use App\Models\Node;

final readonly class SqliteNodeDatabaseInspector
{
    public const int ROW_LIMIT = 500;

    public function __construct(
        private AgentCommandRunner $agents,
        private DatabaseResultRedactor $redactor,
    ) {}

    public function query(DatabaseConnection $connection, string $sql, bool $write): DatabaseQueryResult
    {
        if ($write) {
            throw new ResourceOperationException('database.operation_required', 'SQL writes run as database.write Operations.', 409);
        }
        $decoded = $this->run($connection, $sql, $write);

        $columns = [];
        $rows = [];
        $truncated = $decoded['truncated'] === true;

        $decodedColumns = $decoded['columns'] ?? null;
        foreach (is_array($decodedColumns) ? $decodedColumns : [] as $column) {
            if (is_string($column)) {
                $columns[] = $column;
            }
        }

        $decodedRows = $decoded['rows'] ?? null;
        foreach (is_array($decodedRows) ? $decodedRows : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            if (count($rows) >= self::ROW_LIMIT) {
                $truncated = true;

                break;
            }

            $normalized = [];

            foreach ($row as $key => $value) {
                $name = (string) $key;
                $normalized[$name] = is_bool($value) || is_int($value) || is_float($value) || is_string($value) || $value === null
                    ? $value
                    : null;
            }

            $rows[] = $normalized;
        }

        $rowCount = $decoded['row_count'] ?? count($rows);
        $rowCount = is_int($rowCount) ? max($rowCount, 0) : count($rows);

        return $this->redactor->query(
            $connection,
            new DatabaseQueryResult($columns, $rows, $rowCount, $truncated),
        );
    }

    /** @return list<string> */
    public function tables(DatabaseConnection $connection): array
    {
        $result = $this->query(
            $connection,
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
            false,
        );
        $tables = [];

        foreach ($result->rows as $row) {
            if (is_string($row['name'] ?? null) && $row['name'] !== '') {
                $tables[] = $row['name'];
            }
        }

        return $this->redactor->tables($connection->password, $tables);
    }

    /** @return list<DatabaseSchemaTable> */
    public function schema(DatabaseConnection $connection): array
    {
        $tables = [];

        foreach ($this->tables($connection) as $table) {
            $tables[] = new DatabaseSchemaTable($table, $this->describe($connection, $table));
        }

        return $this->redactor->schema($connection->password, $tables);
    }

    /** @return list<DatabaseTableColumn> */
    public function describe(DatabaseConnection $connection, string $table): array
    {
        $quoted = '"'.str_replace('"', '""', $table).'"';
        $result = $this->query($connection, 'PRAGMA table_info('.$quoted.')', false);
        $columns = array_map(static fn (array $row): DatabaseTableColumn => new DatabaseTableColumn(
            (string) ($row['name'] ?? ''),
            (string) ($row['type'] ?? ''),
            ! (bool) ($row['notnull'] ?? false),
            isset($row['dflt_value']) ? (string) $row['dflt_value'] : null,
            (int) ($row['pk'] ?? 0) > 0,
        ), $result->rows);

        if ($columns === []) {
            throw new ResourceOperationException(
                errorCode: 'database.table_missing',
                message: "Table [{$table}] was not found.",
                status: 404,
            );
        }

        return $this->redactor->columns($connection->password, $columns);
    }

    /** @return array{owner: string, path: string|null, sql: string} */
    public function writeInput(DatabaseConnection $connection, string $sql): array
    {
        $node = $this->owningNode($connection);

        return ['owner' => $this->executionOwner($connection, $node), 'path' => $connection->path, 'sql' => $sql];
    }

    /** @return array<string, mixed> */
    private function run(DatabaseConnection $connection, string $sql, bool $write): array
    {
        $node = $this->owningNode($connection);

        try {
            return $this->agents->run($node, 'database.query-sqlite', [
                'owner' => $this->executionOwner($connection, $node),
                'path' => $connection->path,
                'sql' => $sql,
            ]);
        } catch (AgentCommandRefused $refusal) {
            if ($refusal->errorCode === 'database.sqlite_unreadable') {
                $this->unreadable('The SQLite file is not readable or writable by the inspection user.');
            }

            $this->fail($connection);
        } catch (AgentRunnerFailure) {
            $this->fail($connection);
        }
    }

    private function executionOwner(DatabaseConnection $connection, Node $node): string
    {
        if (! $node->roles->contains('role', RoleName::AppProd)
            || $node->roles->contains('role', RoleName::AppDev)) {
            return $node->user;
        }

        $owner = $connection->ownerInstance;

        if ($owner !== null && $owner->node_id === $node->id
            && is_string($owner->production_user) && $owner->production_user !== '') {
            return $owner->production_user;
        }

        $path = $connection->path ?? '';

        // Do not infer ownership from a path that escapes its apparent home.
        if (! in_array('..', explode('/', $path), true)) {
            foreach ($node->instances()->whereNotNull('production_home')->whereNotNull('production_user')->get() as $instance) {
                $home = rtrim($instance->production_home ?? '', '/');

                if ($home !== '' && $instance->production_user !== ''
                    && str_starts_with($path, $home.'/')) {
                    return (string) $instance->production_user;
                }
            }
        }

        return $node->user;
    }

    private function unreadable(string $reason): never
    {
        throw new ResourceOperationException(
            errorCode: 'database.sqlite_unreadable',
            message: $reason,
            status: 422,
        );
    }

    private function owningNode(DatabaseConnection $connection): Node
    {
        if ($connection->node_id === null) {
            throw new ResourceOperationException(
                errorCode: 'database.sqlite_node_required',
                message: "SQLite connection [{$connection->slug}] requires an associated Node.",
            );
        }

        $node = $connection->node ?? Node::query()->find($connection->node_id);

        if (! $node instanceof Node) {
            throw new ResourceOperationException(
                errorCode: 'database.node_missing',
                message: "Database connection [{$connection->slug}] names a Node that is gone.",
                status: 422,
            );
        }

        if ($node->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException(
                errorCode: 'database.sqlite_node_inactive',
                message: "SQLite connection [{$connection->slug}] requires an active Node.",
            );
        }

        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            throw new ResourceOperationException(
                errorCode: 'database.wireguard_ip_missing',
                message: "Node [{$node->name}] has no WireGuard address.",
            );
        }

        if (! is_string($connection->path) || $connection->path === '') {
            throw new ResourceOperationException(
                errorCode: 'database.query_failed',
                message: "Database connection [{$connection->slug}] query failed.",
                status: 502,
            );
        }

        return $node;
    }

    private function fail(DatabaseConnection $connection): never
    {
        throw new ResourceOperationException(
            errorCode: 'database.query_failed',
            message: "Database connection [{$connection->slug}] query failed.",
            status: 502,
        );
    }
}
