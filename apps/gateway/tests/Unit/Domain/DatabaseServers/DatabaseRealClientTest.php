<?php

declare(strict_types=1);

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\DatabaseImport;
use App\Domain\DatabaseServers\DatabaseRead;

/*
 * The real psql and mysql clients against throwaway postgres:18 and mysql:8.4 containers, through the exact argv that
 * database:query and database:import send. It needs Docker, `sudo -n` and both images on the host, so it runs only with
 * ORBIT_REAL_DATABASE_CLIENTS=1 in the `privileged` group, on a host where throwaway containers are allowed, and it removes
 * them afterwards: `ORBIT_REAL_DATABASE_CLIENTS=1 vendor/bin/pest --group privileged --default-time-limit=300 <this file>`. CI does not run it.
 */

uses()->group('privileged');

beforeEach(function (): void {
    if (getenv('ORBIT_REAL_DATABASE_CLIENTS') !== '1') {
        $this->markTestSkipped('Set ORBIT_REAL_DATABASE_CLIENTS=1 on a host where throwaway database containers are allowed.');
    }
    $this->suffix = bin2hex(random_bytes(4));
    $this->password = bin2hex(random_bytes(12));
    $this->containers = [];
    $this->dir = sys_get_temp_dir().'/orbit-real-client-'.$this->suffix;
    mkdir($this->dir, 0700);
});

afterEach(function (): void {
    foreach ($this->containers ?? [] as $name) {
        exec('sudo -n docker rm --force --volumes '.escapeshellarg($name).' >/dev/null 2>&1');
    }
    if (isset($this->dir)) {
        exec('rm -rf '.escapeshellarg($this->dir));
    }
});

/** @param list<string> $argv @return array{exit: int, out: string} */
function realRun(array $argv, string $stdin = ''): array
{
    $process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);

    return ['exit' => proc_close($process), 'out' => $out];
}

function realServer(object $test, DatabaseDriver $driver): string
{
    $name = 'orbit-real-client-'.($driver === DatabaseDriver::Pgsql ? 'pg' : 'my').'-'.$test->suffix;
    $test->containers[] = $name;
    $env = $driver === DatabaseDriver::Pgsql ? 'POSTGRES_PASSWORD='.$test->password : 'MYSQL_ROOT_PASSWORD='.$test->password;
    expect(realRun(['sudo', '-n', 'docker', 'run', '--detach', '--pull=never', '--name', $name, '--env-file', '/dev/stdin',
        $driver === DatabaseDriver::Pgsql ? 'postgres:18' : 'mysql:8.4'], $env."\n")['exit'])->toBe(0);
    $probe = $driver === DatabaseDriver::Pgsql ? ['pg_isready', '--host=127.0.0.1', '--username=postgres']
        : ['sh', '-c', 'MYSQL_PWD=$1 mysql --protocol=TCP --host=127.0.0.1 --user=root -e "SELECT 1"', 'sh', $test->password];
    for ($i = 0; $i < 120 && realRun(['sudo', '-n', 'docker', 'exec', $name, ...$probe])['exit'] !== 0; $i++) {
        sleep(1);
    }
    $setup = $driver === DatabaseDriver::Pgsql
        ? "CREATE ROLE shop_owner LOGIN PASSWORD '{$test->password}';\nCREATE DATABASE shop OWNER shop_owner;\nCREATE DATABASE other;\n"
            ."\\connect other\nCREATE TABLE note (v text);\nINSERT INTO note VALUES ('untouched');\n\\connect shop\nCREATE TABLE t (id int);\nALTER TABLE t OWNER TO shop_owner;\n"
            ."CREATE DATABASE imported OWNER shop_owner;\n"
        : "CREATE DATABASE shop;\nCREATE DATABASE imported;\nCREATE USER 'shop_owner'@'%' IDENTIFIED BY '{$test->password}';\n"
            ."GRANT ALL ON shop.* TO 'shop_owner'@'%';\nGRANT ALL ON imported.* TO 'shop_owner'@'%';\nCREATE TABLE shop.t (id int);\n";
    $admin = $driver === DatabaseDriver::Pgsql ? ['psql', '-X', '--username=postgres', '--set=ON_ERROR_STOP=1', '--quiet']
        : ['sh', '-c', 'MYSQL_PWD=$1 exec mysql --protocol=TCP --host=127.0.0.1 --user=root', 'sh', $test->password];
    expect(realRun(['sudo', '-n', 'docker', 'exec', '-i', $name, ...$admin], $setup)['exit'])->toBe(0);

    return $name;
}

function realQuery(object $test, string $container, DatabaseDriver $driver, string $sql, bool $write): array
{
    $statements = $write ? DatabaseRead::writeStatements($driver, $sql) : DatabaseRead::statements($driver, 'query', $sql, '');
    $argv = DatabaseRead::argv($driver, ['container' => $container, 'user' => 'shop_owner', 'database' => 'shop'], 'NULL-MARK', $write);

    return realRun($argv, $test->password."\n".$statements);
}

function realFile(string $container, string $path): bool
{
    return realRun(['sudo', '-n', 'docker', 'exec', $container, 'test', '-e', $path])['exit'] === 0;
}

it('runs no psql client command or variable from caller SQL, and still runs plain SQL', function (): void {
    $pg = realServer($this, DatabaseDriver::Pgsql);
    $other = static fn (): string => realRun(['sudo', '-n', 'docker', 'exec', $pg, 'psql', '-X', '-U', 'postgres', '-d', 'other', '-tAc', 'SELECT v FROM note'])['out'];

    foreach ([
        "UPDATE note SET v = 'written' \\c other postgres\n",
        "\\c other postgres\nUPDATE note SET v = 'written'",
        "SELECT 1 \\! touch /tmp/real-client-shell\n",
        '\\! touch /tmp/real-client-shell',
    ] as $sql) {
        expect(realQuery($this, $pg, DatabaseDriver::Pgsql, $sql, true)['exit'])->not->toBe(0);
    }
    $variable = realQuery($this, $pg, DatabaseDriver::Pgsql, "SELECT :'USER' AS v", false);

    expect(trim($other()))->toBe('untouched')
        ->and(realFile($pg, '/tmp/real-client-shell'))->toBeFalse()
        ->and($variable['exit'])->not->toBe(0)->and($variable['out'])->not->toContain('shop_owner');

    $insert = realQuery($this, $pg, DatabaseDriver::Pgsql, 'INSERT INTO t VALUES (1), (2)', true);
    $read = realQuery($this, $pg, DatabaseDriver::Pgsql, 'SELECT count(*) AS n, current_user AS u FROM t', false);

    expect($insert['exit'])->toBe(0)
        ->and(DatabaseRead::written(DatabaseDriver::Pgsql, $insert['out'], 'NULL-MARK')['row_count'])->toBe(2)
        ->and(DatabaseRead::result(DatabaseDriver::Pgsql, 'query', '', $read['out'], 'NULL-MARK', $this->password)['rows'])->toBe([['n' => '2', 'u' => 'shop_owner']]);
});

it('runs no mysql client command from caller SQL, also behind a dollar quote, and still runs plain SQL', function (): void {
    $my = realServer($this, DatabaseDriver::Mysql);

    foreach (['system touch /tmp/real-client-shell', "select \$a\$ '\$a\$;\nsystem touch /tmp/real-client-shell\n'", 'source /etc/hostname'] as $sql) {
        realQuery($this, $my, DatabaseDriver::Mysql, $sql, true);
    }
    $insert = realQuery($this, $my, DatabaseDriver::Mysql, 'INSERT INTO t VALUES (1), (2)', true);

    expect(realFile($my, '/tmp/real-client-shell'))->toBeFalse()
        ->and($insert['exit'])->toBe(0)
        ->and(DatabaseRead::written(DatabaseDriver::Mysql, $insert['out'], 'NULL-MARK')['row_count'])->toBe(2);
});

it('imports a real pg_dump but refuses a backslash command in a dump', function (): void {
    $pg = realServer($this, DatabaseDriver::Pgsql);
    $dump = realRun(['sudo', '-n', 'docker', 'exec', $pg, 'pg_dump', '-U', 'postgres', '--no-owner', '--no-acl', 'other'])['out'];
    file_put_contents($good = $this->dir.'/good.sql', $dump);
    file_put_contents($bad = $this->dir.'/bad.sql', "CREATE TABLE a (id int);\n\\! true\nCREATE TABLE b (id int);\n");
    $import = fn (string $file): string => realRun(DatabaseImport::argv(DatabaseDriver::Pgsql, $file, $pg, 'postgres:18', 'shop_owner', 'imported'),
        $this->password."\n")['out'];

    expect($dump)->toContain('\\restrict ')
        ->and(DatabaseImport::outcome($import($bad)))->toBe(['error_code' => 'database.import_failed',
            'result' => ['database_state' => 'empty', 'exit_code' => 3, 'bytes_read' => filesize($bad), 'tables' => 0]])
        ->and(DatabaseImport::outcome($import($good))['error_code'])->toBeNull();
});

it('imports a MySQL dump with its client commands off', function (): void {
    $my = realServer($this, DatabaseDriver::Mysql);
    file_put_contents($bad = $this->dir.'/bad.sql', "CREATE TABLE a (id int);\nsystem true\n");
    file_put_contents($good = $this->dir.'/good.sql', "CREATE TABLE a (id int);\nINSERT INTO a VALUES (1);\n");
    $import = fn (string $file): array => DatabaseImport::outcome(realRun(DatabaseImport::argv(DatabaseDriver::Mysql, $file, $my, 'mysql:8.4',
        'shop_owner', 'imported'), $this->password."\n")['out']);

    $refused = $import($bad);

    expect($refused['error_code'])->toBe('database.import_failed')
        ->and($refused['result']['error'] ?? '')->toStartWith('ERROR 1064')
        ->and(realRun(['sudo', '-n', 'docker', 'exec', $my, 'sh', '-c', 'MYSQL_PWD=$1 mysql -N --protocol=TCP --host=127.0.0.1 --user=root -e "DROP TABLE imported.a"', 'sh', $this->password])['exit'])->toBe(0)
        ->and($import($good)['error_code'])->toBeNull();
});
