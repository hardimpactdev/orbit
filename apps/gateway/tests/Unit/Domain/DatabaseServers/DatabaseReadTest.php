<?php

declare(strict_types=1);

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\DatabaseRead;
use App\Domain\Shared\ResourceOperationException;

// Output as the mysql 8.4, psql 18 and sqlite3 clients print it for these argv.
const MYSQL_READ_XML = <<<'XML'
<?xml version="1.0"?>

<resultset statement="SELECT a, b, c FROM t" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <row>
	<field name="a">1</field>
	<field name="b" xsi:nil="true" />
	<field name="c">x	y&lt;&amp;&gt;</field>
  </row>
  <row>
	<field name="a">2</field>
	<field name="b"></field>
	<field name="c">password=hunter2</field>
  </row>
</resultset>
XML;

it('reads MySQL XML with NULL apart from an empty string, and redacts secrets and the password', function (): void {
    expect(DatabaseRead::result(DatabaseDriver::Mysql, 'query', '', MYSQL_READ_XML, 'nul', 'x	y'))->toBe([
        'columns' => ['a', 'b', 'c'],
        'rows' => [['a' => '1', 'b' => null, 'c' => '[REDACTED]<&>'], ['a' => '2', 'b' => '', 'c' => 'password=[REDACTED]']],
        'row_count' => 2, 'truncated' => false,
    ]);
});

it('reads CSV with the NULL marker and quoted newlines', function (DatabaseDriver $driver): void {
    $output = "a,b,c\n1,N7,\"x,\"\"y\nz\"\n2,\"\",\n";
    expect(DatabaseRead::result($driver, 'query', '', $output, 'N7', ''))->toBe([
        'columns' => ['a', 'b', 'c'],
        'rows' => [['a' => '1', 'b' => null, 'c' => "x,\"y\nz"], ['a' => '2', 'b' => '', 'c' => '']],
        'row_count' => 2, 'truncated' => false,
    ]);
})->with([DatabaseDriver::Pgsql, DatabaseDriver::Sqlite]);

it('marks output cut at the limit truncated and drops its partial last record', function (): void {
    $output = "a\n1\n".str_repeat('x', DatabaseRead::OUTPUT_LIMIT - 4);
    expect(strlen($output))->toBe(DatabaseRead::OUTPUT_LIMIT);
    expect(DatabaseRead::result(DatabaseDriver::Pgsql, 'query', '', $output, 'N7', ''))
        ->toBe(['columns' => ['a'], 'rows' => [['a' => '1']], 'row_count' => 1, 'truncated' => true]);
});

it('keeps at most 500 rows and marks the rest truncated', function (): void {
    $result = DatabaseRead::result(DatabaseDriver::Sqlite, 'query', '', "a\n".str_repeat("1\n", 501), 'N7', '');
    expect($result['row_count'])->toBe(500)->and($result['truncated'])->toBeTrue();
});

it('shapes catalog reads and reports a missing table', function (): void {
    $columns = "table_name,name,type,nullable,default_value,is_primary\nusers,id,integer,f,N7,t\nusers,email,text,t,'x'::text,f\n";
    expect(DatabaseRead::result(DatabaseDriver::Pgsql, 'describe', 'users', $columns, 'N7', ''))->toBe(['table' => 'users', 'columns' => [
        ['name' => 'id', 'type' => 'integer', 'nullable' => false, 'default' => null, 'primary' => true],
        ['name' => 'email', 'type' => 'text', 'nullable' => true, 'default' => "'x'::text", 'primary' => false],
    ]])
        ->and(DatabaseRead::result(DatabaseDriver::Sqlite, 'describe', 'gone', "table_name,name,type,nullable,default_value,is_primary\n", 'N7', ''))->toBe(['error_code' => 'database.table_missing'])
        ->and(DatabaseRead::result(DatabaseDriver::Sqlite, 'tables', '', "name\nb\na\n", 'N7', ''))->toBe(['tables' => ['b', 'a']]);
});

it('refuses output whose records do not match the header', function (): void {
    DatabaseRead::result(DatabaseDriver::Pgsql, 'query', '', "a,b\n1\n", 'N7', '');
})->throws(ResourceOperationException::class);

it('wraps the read in a READ ONLY transaction and a 30 s limit, and passes the password only on stdin', function (): void {
    expect(DatabaseRead::statements(DatabaseDriver::Mysql, 'query', 'SELECT 1;', ''))->toBe("SET SESSION max_execution_time = 30000;\nSTART TRANSACTION READ ONLY;\nSELECT 1;\nROLLBACK;\n")
        ->and(DatabaseRead::statements(DatabaseDriver::Pgsql, 'query', 'SELECT 1', ''))->toBe("BEGIN READ ONLY;\nSET LOCAL statement_timeout = '30s';\nSELECT 1;\nROLLBACK;\n")
        ->and(DatabaseRead::statements(DatabaseDriver::Sqlite, 'describe', '', 'users'))->toContain("m.name = 'users'");
    $argv = DatabaseRead::argv(DatabaseDriver::Pgsql, ['container' => 'orbit-process-7-db', 'user' => 'app', 'database' => 'app'], 'N7');
    expect(array_slice($argv, 0, 8))->toBe(['timeout', '30', 'sudo', '-n', 'docker', 'exec', '-i', 'orbit-process-7-db'])
        ->and(array_slice($argv, 12))->toBe(['app', 'app', '127.0.0.1', '5432', 'N7'])
        ->and(DatabaseRead::client(DatabaseDriver::Pgsql))->toContain('IFS= read -r PGPASSWORD', '| head -c 65536', 'set -o pipefail');
});

it('allows only listed read verbs and functions on MySQL and PostgreSQL', function (DatabaseDriver $driver, string $sql, bool $allowed): void {
    $refused = false;
    try {
        DatabaseRead::assertReadable($driver, $sql);
    } catch (ResourceOperationException $exception) {
        $refused = $exception->errorCode === 'database.query_refused';
    }
    expect($refused)->toBe(! $allowed);
})->with([
    [DatabaseDriver::Pgsql, 'SELECT count(*) FROM users;', true],
    [DatabaseDriver::Pgsql, 'WITH x AS (SELECT 1) SELECT * FROM x', true],
    [DatabaseDriver::Pgsql, 'SELECT pg_catalog.pg_typeof(1)', true],
    [DatabaseDriver::Pgsql, 'SELECT pg_sleep(1)', false],
    [DatabaseDriver::Pgsql, 'SELECT 1; SELECT 2', false],
    [DatabaseDriver::Mysql, 'SHOW TABLES', true],
    [DatabaseDriver::Mysql, 'CREATE TABLE t (a int)', false],
    [DatabaseDriver::Mysql, 'SELECT LOAD_FILE(\'/etc/passwd\')', false],
    [DatabaseDriver::Mysql, 'SELECT `insert` FROM t', true],
]);
