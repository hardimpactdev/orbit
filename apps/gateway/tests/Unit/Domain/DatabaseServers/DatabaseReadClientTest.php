<?php

declare(strict_types=1);

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\DatabaseRead;

/*
 * The client body that database:query runs, executed for real with sh against a fake `psql` and `mysql`. The fakes
 * record their argv, their stdin and whether the password is in their environment. psql must get the caller's SQL only
 * as a `-c` string, which it sends to the server unlexed: no backslash command and no variable. mysql reads its stdin
 * as client input, so its own commands must be off.
 */

const CLIENT_SECRET = 'owner-pass-sentinel';

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/orbit-client-'.bin2hex(random_bytes(6));
    mkdir($this->dir.'/bin', 0700, true);
    foreach (['psql' => 'PGPASSWORD', 'mysql' => 'MYSQL_PWD'] as $client => $variable) {
        file_put_contents($this->dir.'/bin/'.$client, "#!/bin/sh\nfor a in \"\$@\"; do printf '%s\\0' \"\$a\"; done > \"\$FAKE_DIR/argv\"\n"
            ."cat > \"\$FAKE_DIR/stdin\"\nprintf '%s' \"\${$variable}\" > \"\$FAKE_DIR/password\"\n");
        chmod($this->dir.'/bin/'.$client, 0755);
    }
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->dir));
});

/** @return array{argv: list<string>, stdin: string, password: string} */
function runClient(string $dir, DatabaseDriver $driver, bool $write, string $statements): array
{
    $process = proc_open(['sh', '-c', DatabaseRead::client($driver, $write), 'sh', 'shop_owner', 'shop', '10.0.0.5', '5432', 'NULL-MARK'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir,
        ['PATH' => $dir.'/bin:'.getenv('PATH'), 'FAKE_DIR' => $dir]);
    fwrite($pipes[0], CLIENT_SECRET."\n".$statements);
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    proc_close($process);

    return ['argv' => array_values(array_filter(explode("\0", (string) file_get_contents($dir.'/argv')), static fn (string $a): bool => $a !== '')), 'stdin' => (string) file_get_contents($dir.'/stdin'),
        'password' => (string) file_get_contents($dir.'/password')];
}

it('gives psql the caller SQL only as a -c string, inside its own transaction for a read', function (bool $write, array $commands): void {
    $sql = "\\c other postgres\nSELECT :'USER' \\! id";
    $statements = $write ? DatabaseRead::writeStatements(DatabaseDriver::Pgsql, $sql.';') : DatabaseRead::statements(DatabaseDriver::Pgsql, 'query', $sql.';', '');
    $run = runClient($this->dir, DatabaseDriver::Pgsql, $write, $statements);
    $flags = array_values(array_filter($run['argv'], static fn (string $arg): bool => $arg !== '-c'));
    $index = array_search('-c', $run['argv'], true);

    expect($statements)->toBe($sql)
        ->and($run['stdin'])->toBe('')
        ->and($run['password'])->toBe(CLIENT_SECRET)
        ->and(implode("\n", $run['argv']))->not->toContain(CLIENT_SECRET)
        ->and($run['argv'])->toContain('-X')
        ->and(array_slice($run['argv'], (int) $index))->toBe(array_merge(...array_map(static fn (string $c): array => ['-c', $c],
            array_map(static fn (string $c): string => $c === '<sql>' ? " {$sql}" : $c, $commands))))
        // A leading space: psql runs a -c string that starts with a backslash as a backslash command.
        ->and(in_array("\\c other postgres\nSELECT :'USER' \\! id", $flags, true))->toBeFalse();
})->with([
    'read' => [false, ['BEGIN READ ONLY', "SET LOCAL statement_timeout = '30s'", '<sql>', 'ROLLBACK']],
    'write' => [true, ["SET statement_timeout = '30s'", '<sql>']],
]);

it('runs mysql with its own commands off and the password only in its environment', function (bool $write): void {
    $statements = $write ? DatabaseRead::writeStatements(DatabaseDriver::Mysql, 'UPDATE t SET a = 1')
        : DatabaseRead::statements(DatabaseDriver::Mysql, 'query', 'SELECT 1', '');
    $run = runClient($this->dir, DatabaseDriver::Mysql, $write, $statements);

    expect(array_slice($run['argv'], 0, 2))->toBe(['--skip-commands', '--skip-system-command'])
        ->and($run['stdin'])->toBe($statements)
        ->and($run['password'])->toBe(CLIENT_SECRET)
        ->and(implode("\n", $run['argv']))->not->toContain(CLIENT_SECRET);
})->with(['read' => [false], 'write' => [true]]);
