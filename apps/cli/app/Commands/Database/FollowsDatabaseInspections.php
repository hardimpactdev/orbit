<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Commands\Concerns\FollowsOperations;
use App\Data\GatewayProfile;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseDescribeResponse;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseQueryResponse;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseSchemaResponse;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseTablesResponse;
use Orbit\Sdk\Responses\Operations\OperationResponse;

/** @phpstan-require-extends DatabaseCommand */
trait FollowsDatabaseInspections
{
    use FollowsOperations;

    private function followInspection(GatewayConnector $connector, GatewayProfile $profile, OperationResponse $operation, string $slug): int
    {
        $status = $this->followOperation($connector, $profile, $operation, "Inspect Database [{$slug}]", 'Database inspection');
        if ($status !== self::SUCCESS || $this->option('json') === true) {
            return $status;
        }
        $final = $this->send($connector, new ShowOperationRequest($operation->id), OperationResponse::class);
        if (! $final instanceof OperationResponse) {
            return self::FAILURE;
        }
        $result = $final->result ?? [];
        $driver = $final->input['driver'] ?? null;
        if (! is_string($driver)) {
            return self::FAILURE;
        }
        $metadata = ['slug' => $slug, 'driver' => $driver];
        if (isset($result['rows'], $result['columns'])) {
            $query = DatabaseQueryResponse::fromGatewayData([...$result, ...$metadata, 'write' => false], $final->requestId);
            ConsoleWriter::write($this->output, $this->humanRenderer()->detail("Database query [{$slug}].", ['Write permission' => 'no', 'Reported row count' => $query->rowCount, 'Truncated' => $query->truncated ? 'yes' : 'no', 'Request ID' => $final->requestId]));
            $columns = $query->columns;
            $rows = array_map(fn (array $row): array => array_map(fn (string $column): string => $this->queryCell($row[$column] ?? null), $columns), $query->rows);
            if ($query->truncated) {
                $this->writeHumanMessage('Result truncated at the native read limit. The omitted total is not known.');
            }

            return $this->renderInspectionTable("Database query [{$slug}].", $columns, $rows, $final->requestId);
        }
        if (isset($result['table'])) {
            $describe = DatabaseDescribeResponse::fromGatewayData([...$result, ...$metadata], $final->requestId);

            return $this->renderInspectionTable("Database table [{$slug}.{$describe->table}].", ['Column', 'Type', 'Nullable', 'Default', 'Primary'], $this->inspectionColumns($describe->columns), $final->requestId);
        }
        $tables = $result['tables'] ?? [];
        if (is_array($tables) && ($tables === [] || is_string($tables[0] ?? null))) {
            $names = DatabaseTablesResponse::fromGatewayData([...$result, ...$metadata], $final->requestId);

            return $this->renderInspectionTable("Database tables [{$slug}].", ['Table'], array_map(static fn (string $table): array => [$table], $names->tables), $final->requestId);
        }
        $schema = DatabaseSchemaResponse::fromGatewayData([...$result, ...$metadata], $final->requestId);
        $rows = [];
        foreach ($schema->tables as $table) {
            foreach ($this->inspectionColumns($table['columns']) as $column) {
                $rows[] = [$table['name'], ...$column];
            }
        }

        return $this->renderInspectionTable("Database schema [{$slug}].", ['Table', 'Column', 'Type', 'Nullable', 'Default', 'Primary'], $rows, $final->requestId);
    }

    private function queryCell(bool|float|int|string|null $value): string
    {
        return $value === null ? 'NULL' : $this->displayValue($value);
    }

    /** @param list<array{name: string, type: string, nullable: bool, default: string|null, primary: bool}> $columns
     * @return list<list<string>>
     */
    private function inspectionColumns(array $columns): array
    {
        return array_map(fn (array $column): array => [$column['name'], $column['type'], $column['nullable'] ? 'yes' : 'no', $this->displayValue($column['default']), $column['primary'] ? 'yes' : 'no'], $columns);
    }
}
