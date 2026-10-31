<?php

declare(strict_types=1);

require __DIR__.'/read_policy.php';

try {
    $intent = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
    $p = json_decode(stream_get_contents(STDIN, 65537), true, flags: JSON_THROW_ON_ERROR);
    $driver = $intent['driver'];
    $mode = $intent['mode'];
    if (!in_array($driver, ['mysql', 'pgsql'], true) || !in_array($mode, ['query', 'tables', 'schema', 'describe'], true)
        || !is_string($p['dsn']) || strlen($p['dsn']) > 4096 || !str_starts_with($p['dsn'], $driver.':')
        || !is_string($p['password']) || strlen($p['password']) > 16384
        || (!is_null($p['username']) && (!is_string($p['username']) || strlen($p['username']) > 128))
        || !is_string($p['sql']) || strlen($p['sql']) > 16384
        || str_contains(implode('', array_filter($p, 'is_string')), "\0")) {
        throw new RuntimeException;
    }
    if ($mode === 'query') {
        assertReadSql($p['sql'], $driver);
    }
    $mysql = $driver === 'mysql';
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10, PDO::ATTR_EMULATE_PREPARES => false];
    if ($mysql) {
        $options[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = false;
    }
    $db = new PDO($p['dsn'], $p['username'], $p['password'], $options);
    $db->exec($mysql ? 'START TRANSACTION READ ONLY' : 'BEGIN READ ONLY');
    if (!$mysql) {
        $db->exec("SET LOCAL statement_timeout = '10s'");
    }
    $query = static function (string $sql, array $parameters = []) use ($db): array {
        $statement = $db->prepare($sql);
        $statement->execute($parameters);
        $rows = $columns = [];
        $truncated = false;
        if ($statement->columnCount() > 500) {
            throw new RuntimeException;
        }
        for ($index = 0; $index < $statement->columnCount(); $index++) {
            $columns[] = (string) ($statement->getColumnMeta($index)['name'] ?? $index);
        }
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            foreach ($row as &$value) {
                if (!is_scalar($value) && $value !== null) {
                    $value = null;
                }
            }
            unset($value);
            if (count($rows) >= 500 || strlen(json_encode([$columns, $rows, $row], JSON_THROW_ON_ERROR)) > 11000) {
                $truncated = true;
                break;
            }
            $rows[] = $row;
        }
        $statement->closeCursor();
        return ['columns' => $columns, 'rows' => $rows, 'row_count' => count($rows), 'truncated' => $truncated];
    };
    $tables = static fn (): array => array_column($query($mysql ? "SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME" : 'SELECT tablename AS name FROM pg_catalog.pg_tables WHERE schemaname=current_schema() ORDER BY tablename')['rows'], 'name');
    $describe = static function (string $table) use ($mysql, $query): array {
        $sql = $mysql ? 'SELECT COLUMN_NAME AS name,COLUMN_TYPE AS type,IS_NULLABLE AS nullable,COLUMN_DEFAULT AS default_value,COLUMN_KEY AS is_primary FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION' : 'SELECT a.attname AS name,pg_catalog.format_type(a.atttypid,a.atttypmod) AS type,NOT a.attnotnull AS nullable,pg_get_expr(ad.adbin,ad.adrelid) AS default_value,COALESCE(i.indisprimary,false) AS is_primary FROM pg_catalog.pg_attribute a LEFT JOIN pg_catalog.pg_attrdef ad ON a.attrelid=ad.adrelid AND a.attnum=ad.adnum LEFT JOIN pg_catalog.pg_index i ON a.attrelid=i.indrelid AND a.attnum=ANY(i.indkey) AND i.indisprimary WHERE a.attrelid=to_regclass(quote_ident(?)) AND a.attnum>0 AND NOT a.attisdropped ORDER BY a.attnum';
        return array_map(static fn (array $row): array => ['name' => (string) $row['name'], 'type' => (string) $row['type'], 'nullable' => $mysql ? $row['nullable'] === 'YES' : in_array($row['nullable'], [true, 1, '1', 't'], true), 'default' => $row['default_value'] === null ? null : (string) $row['default_value'], 'primary' => $mysql ? $row['is_primary'] === 'PRI' : in_array($row['is_primary'], [true, 1, '1', 't'], true)], $query($sql, [$table])['rows']);
    };
    $result = match ($mode) {
        'query' => $query($p['sql']),
        'tables' => ['tables' => $tables()],
        'schema' => ['tables' => array_map(static fn (string $table): array => ['name' => $table, 'columns' => $describe($table)], $tables())],
        'describe' => ['table' => $intent['table'], 'columns' => $describe($intent['table'])],
    };
    $db->rollBack();
    if ($mode === 'describe' && $result['columns'] === []) {
        $result = ['error_code' => 'database.table_missing'];
    }
    $json = json_encode(redact($result, $p['password']), JSON_THROW_ON_ERROR);
    if (strlen($json) > 15000) {
        throw new RuntimeException;
    }
    echo $json."\n";
    exit(isset($result['error_code']) ? 1 : 0);
} catch (Throwable) {
    echo '{"error_code":"database.query_failed"}'."\n";
    exit(1);
}
