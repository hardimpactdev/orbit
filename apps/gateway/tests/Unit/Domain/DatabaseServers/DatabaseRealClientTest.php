<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\DatabaseServers;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\DatabaseImport;
use App\Domain\DatabaseServers\DatabaseRead;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Large;
use Tests\Support\GuardsSudo;
use Tests\TestCase;

/*
 * The real psql and mysql clients against throwaway postgres:18 and mysql:8.4 containers, through the exact argv that
 * database:query and database:import send. It needs Docker, `sudo -n` and both images on the host, so it runs only with
 * ORBIT_REAL_DATABASE_CLIENTS=1 in the `privileged` group, on a host where throwaway containers are allowed, and it removes
 * them afterwards. The Gateway real database clients CI lane runs this file with skips treated as failures.
 */

#[Group('privileged')]
// Each case initializes a private real server; MySQL initialization and imports exceed the five-second default.
#[Large]
final class DatabaseRealClientTest extends TestCase
{
    use GuardsSudo;

    private string $suffix;

    private string $password;

    /** @var list<string> */
    private array $containers = [];

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('ORBIT_REAL_DATABASE_CLIENTS') !== '1') {
            $this->markTestSkipped('Set ORBIT_REAL_DATABASE_CLIENTS=1 on a host where throwaway database containers are allowed.');
        }
        $this->suffix = bin2hex(random_bytes(4));
        $this->password = bin2hex(random_bytes(12));
        $this->containers = [];
        $this->dir = sys_get_temp_dir().'/orbit-real-client-'.$this->suffix;
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->containers ?? [] as $name) {
                exec('sudo -n docker rm --force --volumes '.escapeshellarg($name).' >/dev/null 2>&1');
            }
            if (isset($this->dir)) {
                exec('rm -rf '.escapeshellarg($this->dir));
            }
        } finally {
            parent::tearDown();
        }
    }

    /** @param list<string> $argv @return array{exit: int, out: string} */
    private static function realRun(array $argv, string $stdin = ''): array
    {
        $process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);

        return ['exit' => proc_close($process), 'out' => $out];
    }

    private static function realServer(self $test, DatabaseDriver $driver): string
    {
        $name = 'orbit-real-client-'.($driver === DatabaseDriver::Pgsql ? 'pg' : 'my').'-'.$test->suffix;
        $test->containers[] = $name;
        $env = $driver === DatabaseDriver::Pgsql ? 'POSTGRES_PASSWORD='.$test->password : 'MYSQL_ROOT_PASSWORD='.$test->password;
        expect(self::realRun(['sudo', '-n', 'docker', 'run', '--detach', '--pull=never', '--name', $name, '--env-file', '/dev/stdin',
            $driver === DatabaseDriver::Pgsql ? 'postgres:18' : 'mysql:8.4'], $env."\n")['exit'])->toBe(0);
        $probe = $driver === DatabaseDriver::Pgsql ? ['pg_isready', '--host=127.0.0.1', '--username=postgres']
            : ['sh', '-c', 'MYSQL_PWD=$1 mysql --protocol=TCP --host=127.0.0.1 --user=root -e "SELECT 1"', 'sh', $test->password];
        for ($i = 0; $i < 120 && self::realRun(['sudo', '-n', 'docker', 'exec', $name, ...$probe])['exit'] !== 0; $i++) {
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
        expect(self::realRun(['sudo', '-n', 'docker', 'exec', '-i', $name, ...$admin], $setup)['exit'])->toBe(0);

        return $name;
    }

    private static function realQuery(self $test, string $container, DatabaseDriver $driver, string $sql, bool $write): array
    {
        $statements = $write ? DatabaseRead::writeStatements($driver, $sql) : DatabaseRead::statements($driver, 'query', $sql, '');
        $argv = DatabaseRead::argv($driver, ['container' => $container, 'user' => 'shop_owner', 'database' => 'shop'], 'NULL-MARK', $write);

        return self::realRun($argv, $test->password."\n".$statements);
    }

    private static function realFile(string $container, string $path): bool
    {
        return self::realRun(['sudo', '-n', 'docker', 'exec', $container, 'test', '-e', $path])['exit'] === 0;
    }

    public function test_psql_refuses_client_commands_and_variables_but_runs_plain_sql(): void
    {
        $pg = self::realServer($this, DatabaseDriver::Pgsql);
        $other = static fn (): string => self::realRun(['sudo', '-n', 'docker', 'exec', $pg, 'psql', '-X', '-U', 'postgres', '-d', 'other', '-tAc', 'SELECT v FROM note'])['out'];

        foreach ([
            "UPDATE note SET v = 'written' \\c other postgres\n",
            "\\c other postgres\nUPDATE note SET v = 'written'",
            "SELECT 1 \\! touch /tmp/real-client-shell\n",
            '\\! touch /tmp/real-client-shell',
        ] as $sql) {
            expect(self::realQuery($this, $pg, DatabaseDriver::Pgsql, $sql, true)['exit'])->not->toBe(0);
        }
        $variable = self::realQuery($this, $pg, DatabaseDriver::Pgsql, "SELECT :'USER' AS v", false);

        expect(trim($other()))->toBe('untouched')
            ->and(self::realFile($pg, '/tmp/real-client-shell'))->toBeFalse()
            ->and($variable['exit'])->not->toBe(0)->and($variable['out'])->not->toContain('shop_owner');

        $insert = self::realQuery($this, $pg, DatabaseDriver::Pgsql, 'INSERT INTO t VALUES (1), (2)', true);
        $read = self::realQuery($this, $pg, DatabaseDriver::Pgsql, 'SELECT count(*) AS n, current_user AS u FROM t', false);

        expect($insert['exit'])->toBe(0)
            ->and(DatabaseRead::written(DatabaseDriver::Pgsql, $insert['out'], 'NULL-MARK')['row_count'])->toBe(2)
            ->and(DatabaseRead::result(DatabaseDriver::Pgsql, 'query', '', $read['out'], 'NULL-MARK', $this->password)['rows'])->toBe([['n' => '2', 'u' => 'shop_owner']]);
    }

    public function test_mysql_refuses_client_commands_even_behind_a_dollar_quote_but_runs_plain_sql(): void
    {
        $my = self::realServer($this, DatabaseDriver::Mysql);

        foreach (['system touch /tmp/real-client-shell', "select \$a\$ '\$a\$;\nsystem touch /tmp/real-client-shell\n'", 'source /etc/hostname'] as $sql) {
            self::realQuery($this, $my, DatabaseDriver::Mysql, $sql, true);
        }
        $insert = self::realQuery($this, $my, DatabaseDriver::Mysql, 'INSERT INTO t VALUES (1), (2)', true);

        expect(self::realFile($my, '/tmp/real-client-shell'))->toBeFalse()
            ->and($insert['exit'])->toBe(0)
            ->and(DatabaseRead::written(DatabaseDriver::Mysql, $insert['out'], 'NULL-MARK')['row_count'])->toBe(2);
    }

    public function test_imports_a_real_pg_dump_but_refuses_a_backslash_command(): void
    {
        $pg = self::realServer($this, DatabaseDriver::Pgsql);
        $dump = self::realRun(['sudo', '-n', 'docker', 'exec', $pg, 'pg_dump', '-U', 'postgres', '--no-owner', '--no-acl', 'other'])['out'];
        file_put_contents($good = $this->dir.'/good.sql', $dump);
        file_put_contents($bad = $this->dir.'/bad.sql', "CREATE TABLE a (id int);\n\\! true\nCREATE TABLE b (id int);\n");
        $import = fn (string $file): string => self::realRun(DatabaseImport::argv(DatabaseDriver::Pgsql, $file, $pg, 'postgres:18', 'shop_owner', 'imported'),
            $this->password."\n")['out'];

        expect($dump)->toContain('\\restrict ')
            ->and(DatabaseImport::outcome($import($bad)))->toBe(['error_code' => 'database.import_failed',
                'result' => ['database_state' => 'empty', 'exit_code' => 3, 'bytes_read' => filesize($bad), 'tables' => 0]])
            ->and(DatabaseImport::outcome($import($good))['error_code'])->toBeNull();
    }

    public function test_imports_a_mysql_dump_with_its_client_commands_off(): void
    {
        $my = self::realServer($this, DatabaseDriver::Mysql);
        file_put_contents($bad = $this->dir.'/bad.sql', "CREATE TABLE a (id int);\nsystem true\n");
        file_put_contents($good = $this->dir.'/good.sql', "CREATE TABLE a (id int);\nINSERT INTO a VALUES (1);\n");
        $import = fn (string $file): array => DatabaseImport::outcome(self::realRun(DatabaseImport::argv(DatabaseDriver::Mysql, $file, $my, 'mysql:8.4',
            'shop_owner', 'imported'), $this->password."\n")['out']);

        $refused = $import($bad);

        expect($refused['error_code'])->toBe('database.import_failed')
            ->and($refused['result']['error'] ?? '')->toStartWith('ERROR 1064')
            ->and(self::realRun(['sudo', '-n', 'docker', 'exec', $my, 'sh', '-c', 'MYSQL_PWD=$1 mysql -N --protocol=TCP --host=127.0.0.1 --user=root -e "DROP TABLE imported.a"', 'sh', $this->password])['exit'])->toBe(0)
            ->and($import($good)['error_code'])->toBeNull();
    }
}
