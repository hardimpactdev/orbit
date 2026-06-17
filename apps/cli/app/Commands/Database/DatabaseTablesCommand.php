<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\table;

final class DatabaseTablesCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'database:tables
        {target? : App, workspace, or connection slug}
        {--connection= : Connection slug when the target maps to multiple connections}
        {--json}';

    #[\Override]
    protected $description = 'List tables for a registered database connection.';

    public function handle(): int
    {
        $target = $this->stringArgument('target');

        if ($target === null) {
            return $this->renderFailure('validation_failed', 'The target argument is required.', ['field' => 'target']);
        }

        try {
            $response = $this->gatewayGet('/api/database-connections/tables', $this->filledQuery([
                'target' => $target,
                'connection' => $this->stringOption('connection'),
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $data = $this->successData($response);
        $rows = $this->resultRows($data);
        $columns = $this->resultColumns($data, $rows);

        $this->line('Showing '.count($rows).' table(s).');

        table(
            headers: array_map(mb_strtoupper(...), $columns),
            rows: array_map(fn (array $row): array => array_map(
                fn (string $column): string => $this->cellValue($row[$column] ?? null),
                $columns,
            ), $rows),
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function successData(array $response): array
    {
        $success = $response['success'] ?? null;

        if (is_array($success) && is_array($success['data'] ?? null)) {
            return $success['data'];
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function resultRows(array $data): array
    {
        if (! is_array($data['rows'] ?? null)) {
            return [];
        }

        return array_values(array_filter($data['rows'], is_array(...)));
    }

    /**
     * Resolve the ordered column keys from the driver-reported `columns`
     * metadata, falling back to the first row's keys when absent.
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function resultColumns(array $data, array $rows): array
    {
        $columns = [];

        if (is_array($data['columns'] ?? null)) {
            foreach ($data['columns'] as $column) {
                if (is_string($column) && $column !== '') {
                    $columns[] = $column;
                }
            }
        }

        if ($columns === [] && $rows !== []) {
            $columns = array_map(strval(...), array_keys($rows[0]));
        }

        return $columns === [] ? ['value'] : $columns;
    }

    private function cellValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value) && (string) $value !== '') {
            return (string) $value;
        }

        return '—';
    }
}
