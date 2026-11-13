<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Shared\ResourceOperationException;
use App\Support\ValidatedData;
use XMLReader;

/**
 * One bounded read or write: the Gateway checks a read, builds the client argv and its stdin, and shapes the client's
 * output into rows or an affected row count. The client runs over official SSH or on 209; no script ships to the host.
 */
final readonly class DatabaseRead
{
    /** The client output stops here; a longer answer is truncated. */
    public const int OUTPUT_LIMIT = 65536;

    private const int ROW_LIMIT = 500;

    private const int ROWS_JSON_LIMIT = 11000;

    private const int RESULT_JSON_LIMIT = 15000;

    /** The functions a read may call. Others, such as sleep or backend control, are refused before any run. */
    private const string FUNCTIONS = 'abs acos asin atan atan2 avg bit_length cast ceil ceiling char_length character_length coalesce concat concat_ws convert cos count current_date current_time current_timestamp database date date_format date_part date_trunc datediff day decode dense_rank encode exp extract first_value floor format greatest group_concat hex initcap instr json_array json_array_length json_arrayagg json_build_array json_build_object json_extract json_object json_objectagg json_query json_type json_unquote json_value json_agg jsonb_agg jsonb_array_length jsonb_build_array jsonb_build_object jsonb_typeof lag last_value lead least left length ln locate log log10 lower lpad ltrim max md5 min mod month now ntile nullif octet_length percentile_cont percentile_disc position power rand random rank regexp_replace replace reverse right round row_number rpad rtrim sha1 sha2 sign sin split_part sqrt stddev stddev_pop stddev_samp string_agg substr substring sum tan timestampdiff to_char to_date to_timestamp translate trim trunc truncate unhex unix_timestamp upper user variance var_pop var_samp version year array_agg generate_series pg_column_size pg_database_size pg_indexes_size pg_relation_size pg_size_pretty pg_table_size pg_total_relation_size pg_typeof';

    private const string WRITES = 'into insert update delete create alter drop truncate grant revoke copy call do execute set commit rollback begin start lock unlock';

    /**
     * MySQL and PostgreSQL run the statement inside a READ ONLY transaction, but MySQL DDL commits that transaction
     * itself. So a query reads only with the listed verbs and functions; the server refuses the rest.
     */
    public static function assertReadable(DatabaseDriver $driver, string $sql): void
    {
        if ($driver === DatabaseDriver::Sqlite) {
            return;
        }
        if (trim($sql) === '' || str_contains($sql, '\\') || str_contains($sql, "\0")) {
            self::refuse();
        }
        preg_match_all('~\'(?:\'\'|[^\'])*\'|"(?:""|[^"])*"|`(?:``|[^`])*`|--[^\n]*|\#[^\n]*|/\*[\s\S]*?\*/|[a-z_][a-z0-9_$]*|[^\s]~i', $sql, $matches);
        $raw = $matches[0];
        $tokens = [];
        foreach ($raw as $word) {
            if (preg_match('~^(?:--|\#|/\*)~', $word) === 1) {
                self::refuse();
            }
            $tokens[] = str_starts_with($word, "'") ? '<literal>'
                : strtolower(in_array($word[0], ['"', '`'], true) ? str_replace(['""', '``'], ['"', '`'], substr($word, 1, -1)) : $word);
        }
        if (! in_array($tokens[0] ?? '', ['select', 'with', 'show', 'explain', 'describe'], true)) {
            self::refuse();
        }
        $functions = explode(' ', self::FUNCTIONS);
        $writes = explode(' ', self::WRITES);
        foreach ($tokens as $index => $word) {
            if ($word === '$' || ($word === ';' && $index !== count($tokens) - 1)
                || (in_array($word, $writes, true) && ! in_array($raw[$index][0], ['"', '`'], true))) {
                self::refuse();
            }
            if (preg_match('/^[a-z_]/', $word) === 1 && ($tokens[$index + 1] ?? '') === '(') {
                if ((! in_array($word, $functions, true) && ! in_array($word, ['as', 'in', 'exists', 'over', 'filter', 'select', 'values'], true))
                    || (($tokens[$index - 1] ?? '') === '.' && ($driver !== DatabaseDriver::Pgsql || ($tokens[$index - 2] ?? '') !== 'pg_catalog'))) {
                    self::refuse();
                }
            }
        }
    }

    /**
     * What follows the password line: one bounded read and nothing that stays. For PostgreSQL that is the read alone; the
     * client sends it with `-c` inside its own READ ONLY transaction ({@see client()}).
     */
    public static function statements(DatabaseDriver $driver, string $mode, string $sql, string $table): string
    {
        $read = match ($mode) {
            'query' => rtrim(rtrim($sql), ';'),
            'tables' => self::tables($driver),
            default => self::columns($driver, $mode === 'describe' ? $table : null),
        };

        return match ($driver) {
            DatabaseDriver::Mysql => "SET SESSION max_execution_time = 30000;\nSTART TRANSACTION READ ONLY;\n{$read};\nROLLBACK;\n",
            DatabaseDriver::Pgsql => $read,
            default => ".timeout 10000\n{$read};\n",
        };
    }

    /**
     * The client argv over official SSH: on a Database server's Node (docker exec into its container) or as the SQLite
     * file's owner. Each client reads its password from the first stdin line, never argv or a file, and its output stops
     * at OUTPUT_LIMIT. `$null` marks SQL NULL in CSV, apart from an empty string.
     *
     * @param  array<string, mixed>  $target  container, user and database; or owner and path
     * @return list<string>
     */
    public static function argv(DatabaseDriver $driver, array $target, string $null, bool $write = false): array
    {
        $text = static fn (string $key): string => ValidatedData::nullableString($target[$key] ?? null) ?? '';
        if ($driver === DatabaseDriver::Sqlite) {
            return ['sudo', '-n', '-u', $text('owner'), '--', 'timeout', '30', 'sh', '-c',
                'set -o pipefail; sqlite3 '.($write ? '' : '-readonly ').'-safe -bail -csv -header -nullvalue "$2" "$1" | head -c '.self::OUTPUT_LIMIT,
                'sh', $text('path'), $null];
        }

        return ['timeout', '30', 'sudo', '-n', 'docker', 'exec', '-i', $text('container'), 'sh', '-c', self::client($driver, $write),
            'sh', $text('user'), $text('database'), '127.0.0.1', $driver === DatabaseDriver::Pgsql ? '5432' : '3306', $null];
    }

    /**
     * The client body for `sh -c`, with the user, database, host, port and NULL marker as $1 to $5. Run in a container,
     * or on 209 for a registered external database.
     */
    public static function client(DatabaseDriver $driver, bool $write = false): string
    {
        // The caller's SQL never reaches a client that interprets it (docs/reference/database-connections.md#client-commands).
        // psql sends a `-c` string to the server as it is: no backslash command and no variable. The leading space keeps
        // psql from reading a statement that starts with a backslash as one. The password stays the first stdin line.
        // mysql reads its stdin as client input, so it runs with its own commands, such as system and source, off; a
        // client without these options refuses to start. A write keeps psql's command tag, such as UPDATE 2: it is the
        // affected row count.
        $transaction = $write ? '-c "SET statement_timeout = \'30s\'" -c " $sql"'
            : '-c "BEGIN READ ONLY" -c "SET LOCAL statement_timeout = \'30s\'" -c " $sql" -c ROLLBACK';

        return 'set -o pipefail; '.($driver === DatabaseDriver::Pgsql
            ? 'IFS= read -r PGPASSWORD && export PGPASSWORD && sql=$(cat) && psql -X --host="$3" --port="$4" --username="$1" --dbname="$2"'.($write ? '' : ' --quiet').' --set=ON_ERROR_STOP=1 --csv --pset=null="$5" '.$transaction
            : 'IFS= read -r MYSQL_PWD && export MYSQL_PWD && mysql --skip-commands --skip-system-command --protocol=TCP --host="$3" --port="$4" --user="$1" --database="$2" --xml')
            .' | head -c '.self::OUTPUT_LIMIT;
    }

    /** What follows the password line for a write: the statement, then its affected row count. PostgreSQL: the statement alone. */
    public static function writeStatements(DatabaseDriver $driver, string $sql): string
    {
        $statement = rtrim(rtrim($sql), ';');

        return match ($driver) {
            DatabaseDriver::Mysql => "SET SESSION max_execution_time = 30000;\n{$statement};\nSELECT ROW_COUNT() AS row_count;\n",
            DatabaseDriver::Pgsql => $statement,
            default => ".timeout 10000\n{$statement};\nSELECT changes() AS row_count;\n",
        };
    }

    /**
     * A write's result: the affected row count from the client's last line, and whether the output reached its bound.
     *
     * @return array{backend: string, row_count: int, truncated: bool}
     */
    public static function written(DatabaseDriver $driver, string $output, string $null): array
    {
        $truncated = strlen($output) >= self::OUTPUT_LIMIT;
        // The count is the client's last output: psql's command tag, the last XML row, or sqlite3's last line. Rows the
        // statement itself returned come before it, each result set with its own header.
        $lines = preg_split('/\R/', trim($output)) ?: [];
        $last = (string) end($lines);
        if ($driver === DatabaseDriver::Pgsql) {
            $count = preg_match('/\A(?:INSERT [0-9]+|UPDATE|DELETE|MERGE|SELECT|COPY|MOVE|FETCH) ([0-9]+)\z/D', $last, $match) === 1 ? (int) $match[1] : 0;
        } else {
            if ($driver === DatabaseDriver::Mysql) {
                [, $records] = self::xml($output);
                $row = end($records);
                $last = is_array($row) ? (string) ($row['row_count'] ?? '') : '';
            }
            if ($truncated || preg_match('/\A-?[0-9]+\z/D', $last) !== 1) {
                self::failed();
            }
            $count = (int) $last;
        }

        return ['backend' => 'active', 'row_count' => max(0, $count), 'truncated' => $truncated];
    }

    /**
     * The result a read returns: rows for a query, names for tables, columns for describe and schema.
     *
     * @return array<string, mixed>
     */
    public static function result(DatabaseDriver $driver, string $mode, string $table, string $output, string $null, string $password): array
    {
        $truncated = strlen($output) >= self::OUTPUT_LIMIT;
        [$columns, $records] = $driver === DatabaseDriver::Mysql ? self::xml($output) : self::csv($output, $null, $truncated);
        $result = match ($mode) {
            'query' => self::rows($columns, $records, $truncated),
            'tables' => ['tables' => array_map(static fn (array $row): string => (string) $row['name'], $records)],
            'describe' => $records === [] ? ['error_code' => 'database.table_missing']
                : ['table' => $table, 'columns' => array_map(self::column(...), $records)],
            default => ['tables' => self::schema($records)],
        };
        $result = ValidatedData::object(self::redact($result, $password));
        if (strlen(json_encode($result, JSON_THROW_ON_ERROR)) > self::RESULT_JSON_LIMIT) {
            return ['error_code' => 'database.query_failed'];
        }

        return $result;
    }

    private static function tables(DatabaseDriver $driver): string
    {
        return match ($driver) {
            DatabaseDriver::Mysql => "SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME",
            DatabaseDriver::Pgsql => 'SELECT tablename AS name FROM pg_catalog.pg_tables WHERE schemaname = current_schema() ORDER BY tablename',
            default => "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
        };
    }

    /** One catalog query for one table (describe) or every table (schema), in column order. The table name is a checked identifier. */
    private static function columns(DatabaseDriver $driver, ?string $table): string
    {
        return match ($driver) {
            DatabaseDriver::Mysql => 'SELECT c.TABLE_NAME AS table_name, c.COLUMN_NAME AS name, c.COLUMN_TYPE AS type, c.IS_NULLABLE = \'YES\' AS nullable, c.COLUMN_DEFAULT AS default_value, c.COLUMN_KEY = \'PRI\' AS is_primary'
                .' FROM information_schema.COLUMNS c JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = \'BASE TABLE\''
                .' WHERE c.TABLE_SCHEMA = DATABASE()'.($table === null ? '' : " AND c.TABLE_NAME = '{$table}'").' ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION',
            DatabaseDriver::Pgsql => 'SELECT c.relname AS table_name, a.attname AS name, pg_catalog.format_type(a.atttypid, a.atttypmod) AS type, NOT a.attnotnull AS nullable,'
                .' pg_catalog.pg_get_expr(ad.adbin, ad.adrelid) AS default_value, COALESCE(i.indisprimary, false) AS is_primary'
                .' FROM pg_catalog.pg_attribute a JOIN pg_catalog.pg_class c ON c.oid = a.attrelid AND c.relkind IN (\'r\', \'p\') AND c.relnamespace = pg_catalog.to_regnamespace(current_schema())'
                .' LEFT JOIN pg_catalog.pg_attrdef ad ON a.attrelid = ad.adrelid AND a.attnum = ad.adnum'
                .' LEFT JOIN pg_catalog.pg_index i ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey) AND i.indisprimary'
                .' WHERE a.attnum > 0 AND NOT a.attisdropped'.($table === null ? '' : " AND c.relname = '{$table}'").' ORDER BY c.relname, a.attnum',
            default => 'SELECT m.name AS table_name, p.name AS name, p.type AS type, NOT p."notnull" AS nullable, p.dflt_value AS default_value, p.pk > 0 AS is_primary'
                ." FROM sqlite_master m JOIN pragma_table_info(m.name) p WHERE m.type = 'table' AND m.name NOT LIKE 'sqlite_%'"
                .($table === null ? '' : " AND m.name = '{$table}'").' ORDER BY m.name, p.cid',
        };
    }

    /**
     * MySQL's --xml marks NULL with xsi:nil. Only complete rows count; a cut-off row at the limit is dropped.
     *
     * @return array{0: list<string>, 1: list<array<string, string|null>>}
     */
    private static function xml(string $output): array
    {
        $columns = [];
        $records = [];
        preg_match_all('~<row>.*?</row>~s', $output, $rows);
        foreach ($rows[0] as $row) {
            $reader = XMLReader::XML('<row xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'.substr($row, 5));
            if (! $reader instanceof XMLReader) {
                self::failed();
            }
            $record = [];
            while (@$reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'field') {
                    $name = (string) $reader->getAttribute('name');
                    $record[$name] = $reader->getAttribute('xsi:nil') === 'true' ? null : $reader->readString();
                }
            }
            $columns = $columns === [] ? array_keys($record) : $columns;
            $records[] = $record;
        }

        return [array_map(strval(...), $columns), $records];
    }

    /**
     * CSV with a header line. `$null` marks SQL NULL. At the limit the last record may be cut off, so it is dropped.
     *
     * @return array{0: list<string>, 1: list<array<string, string|null>>}
     */
    private static function csv(string $output, string $null, bool $truncated): array
    {
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            self::failed();
        }
        fwrite($stream, $output);
        rewind($stream);
        $columns = [];
        $records = [];
        while (($line = fgetcsv($stream, escape: '')) !== false) {
            if ($line === [null]) {
                continue;
            }
            $line = array_map(static fn (?string $value): ?string => $value === $null ? null : $value, $line);
            if ($columns === []) {
                $columns = array_map(strval(...), $line);

                continue;
            }
            if (count($line) !== count($columns)) {
                self::failed();
            }
            $records[] = array_combine($columns, $line);
        }
        fclose($stream);
        if ($truncated) {
            array_pop($records);
        }

        return [$columns, $records];
    }

    /**
     * @param  list<string>  $columns
     * @param  list<array<string, string|null>>  $records
     * @return array{columns: list<string>, rows: list<array<string, string|null>>, row_count: int, truncated: bool}
     */
    private static function rows(array $columns, array $records, bool $truncated): array
    {
        if (count($columns) > self::ROW_LIMIT) {
            self::failed();
        }
        $rows = [];
        foreach ($records as $record) {
            if (count($rows) >= self::ROW_LIMIT || strlen(json_encode([$columns, $rows, $record], JSON_THROW_ON_ERROR)) > self::ROWS_JSON_LIMIT) {
                $truncated = true;
                break;
            }
            $rows[] = $record;
        }

        return ['columns' => $columns, 'rows' => $rows, 'row_count' => count($rows), 'truncated' => $truncated];
    }

    /**
     * @param  array<string, string|null>  $row
     * @return array{name: string, type: string, nullable: bool, default: string|null, primary: bool}
     */
    private static function column(array $row): array
    {
        $true = static fn (?string $value): bool => in_array($value, ['1', 't', 'true'], true);

        return ['name' => (string) $row['name'], 'type' => (string) $row['type'], 'nullable' => $true($row['nullable']),
            'default' => $row['default_value'], 'primary' => $true($row['is_primary'])];
    }

    /**
     * @param  list<array<string, string|null>>  $records
     * @return list<array{name: string, columns: list<array{name: string, type: string, nullable: bool, default: string|null, primary: bool}>}>
     */
    private static function schema(array $records): array
    {
        $tables = [];
        foreach ($records as $record) {
            $tables[(string) $record['table_name']][] = self::column($record);
        }

        return array_map(static fn (string $name, array $columns): array => ['name' => $name, 'columns' => $columns], array_keys($tables), array_values($tables));
    }

    private static function redact(mixed $value, string $password): mixed
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[is_string($key) ? self::redactText($key, $password) : $key] = self::redact($item, $password);
            }

            return $result;
        }

        return is_string($value) ? self::redactText($value, $password) : $value;
    }

    private static function redactText(string $value, string $password): string
    {
        $value = $password !== '' ? str_replace($password, '[REDACTED]', $value) : $value;

        return preg_replace([
            '~-----BEGIN [A-Z0-9 ]+-----[\s\S]*?(?:-----END [A-Z0-9 ]+-----|$)~',
            '~\b([a-z][a-z0-9+.-]*://)[^/@\s]+@~i',
            '~\b((?:Proxy-)?Authorization)\s*:\s*[^\s\'"]+(?:\s+[^\s\'"]+)?~i',
            '~\bBearer\s+(?:"[^"]*"|\'[^\']*\'|[A-Za-z0-9][A-Za-z0-9._\-+/=]{7,})~i',
            '~\b(?:gh[pousr]_[A-Za-z0-9_]{16,}|github_pat_[A-Za-z0-9_]{20,})\b~',
            '~\b((?:[A-Za-z][A-Za-z0-9]*[_-])*(?:TOKENS?|SECRETS?|PASSWORDS?|PASSWD|PASSPHRASE|CREDENTIALS?|BEARER|APIKEY|APPKEY|AUTHTOKEN|ACCESSTOKEN|PRIVATEKEY)(?:[_-](?:HASH|BASE))?)\s*([=:])\s*(?:"[^"]*"|\'[^\']*\'|[^\s&#,}]+)~i',
        ], ['[REDACTED]', '$1[REDACTED]@', '$1: [REDACTED]', 'Bearer [REDACTED]', '[REDACTED]', '$1$2[REDACTED]'], $value) ?? $value;
    }

    private static function refuse(): never
    {
        throw new ResourceOperationException('database.query_refused', 'The read calls a statement, verb or function that a read may not use.', 422);
    }

    private static function failed(): never
    {
        throw new ResourceOperationException('database.query_failed', 'The read returned output Orbit cannot read.', 502);
    }
}
