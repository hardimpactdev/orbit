<?php

declare(strict_types=1);

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\DatabaseImport;
use App\Domain\Shared\ResourceOperationException;

/*
 * The script that database:import sends, run for real with bash, gzip, stat and /proc, against a fake `sudo` and
 * `docker`. The fake client records its argv, the password line and what it was fed, and answers the table counts.
 */

const IMPORT_SECRET = 'owner-pass-sentinel';

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/orbit-import-'.bin2hex(random_bytes(6));
    mkdir($this->dir.'/bin', 0700, true);
    mkdir($this->dir.'/state', 0700);
    mkdir($this->dir.'/dumps', 0700);
    file_put_contents($this->dir.'/bin/sudo', "#!/bin/sh\n[ \"\$1\" = -n ] && shift\nexec \"\$@\"\n");
    file_put_contents($this->dir.'/bin/docker', <<<'SH'
#!/usr/bin/env bash
s=$FAKE_STATE
printf '%s\n' "$*" >> "$s/argv"
IFS= read -r pw; printf '%s\n' "$pw" >> "$s/passwords"
cat > "$s/body"
if grep -q -e information_schema -e pg_class "$s/body"; then cat "$s/tables"; exit 0; fi
cp "$s/body" "$s/loaded"; printf '%s\n' "$*" > "$s/load-argv"
if [ -f "$s/fail" ]; then cat "$s/fail" >&2; cp "$s/tables-after" "$s/tables"; exit 1; fi
cp "$s/tables-after" "$s/tables"
SH);
    chmod($this->dir.'/bin/sudo', 0755);
    chmod($this->dir.'/bin/docker', 0755);
    file_put_contents($this->dir.'/state/tables', "0\n");
    file_put_contents($this->dir.'/state/tables-after', "37\n");
    $this->dump = "CREATE TABLE users (id int);\nINSERT INTO users VALUES (1);\n";
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->dir));
});

/** @return array{line: string, exit: int} */
function runImport(string $dir, DatabaseDriver $driver, string $file, array $extraPath = []): array
{
    $argv = DatabaseImport::argv($driver, $file, 'orbit-process-7-beast-mysql', $driver === DatabaseDriver::Pgsql ? 'postgres:18' : 'mysql:8.4', 'shop_owner', 'shop');
    $process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, [
        'PATH' => implode(':', [...$extraPath, $dir.'/bin', getenv('PATH')]), 'FAKE_STATE' => $dir.'/state',
    ]);
    fwrite($pipes[0], IMPORT_SECRET."\n");
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    $exit = proc_close($process);

    return ['line' => trim((string) $out), 'exit' => $exit];
}

it('loads a plain and a gzipped dump as the owner on MySQL and PostgreSQL', function (DatabaseDriver $driver, bool $gzip): void {
    $file = $this->dir.'/dumps/shop.sql'.($gzip ? '.gz' : '');
    file_put_contents($file, $gzip ? gzencode($this->dump) : $this->dump);
    $run = runImport($this->dir, $driver, $file);

    expect($run['exit'])->toBe(0)
        ->and($run['line'])->toBe('orbit-import outcome=ok state=done exit=0 bytes='.filesize($file).' tables=37')
        ->and(DatabaseImport::outcome($run['line']))->toBe(['error_code' => null, 'result' => ['exit_code' => 0, 'bytes_read' => filesize($file), 'tables' => 37]])
        ->and(file_get_contents($this->dir.'/state/loaded'))->toBe($this->dump)
        // Count, load, count: each login gets the password as its first stdin line, never as an argument.
        ->and(file($this->dir.'/state/passwords', FILE_IGNORE_NEW_LINES))->toBe([IMPORT_SECRET, IMPORT_SECRET, IMPORT_SECRET])
        ->and(file_get_contents($this->dir.'/state/argv'))->not->toContain(IMPORT_SECRET);
    $load = file_get_contents($this->dir.'/state/load-argv');
    expect($load)->toContain('run --rm -i --pull=never --network container:orbit-process-7-beast-mysql --read-only --tmpfs /tmp --cap-drop ALL')
        ->and($load)->toContain('--user 65534:65534 --entrypoint sh')
        ->and(str_ends_with(trim($load), 'sh shop_owner shop'.($driver === DatabaseDriver::Pgsql ? ' --single-transaction' : '')))->toBeTrue();
})->with([
    'mysql plain' => [DatabaseDriver::Mysql, false], 'mysql gzip' => [DatabaseDriver::Mysql, true],
    'pgsql plain' => [DatabaseDriver::Pgsql, false], 'pgsql gzip' => [DatabaseDriver::Pgsql, true],
]);

it('refuses a database with tables before it loads anything', function (): void {
    file_put_contents($this->dir.'/state/tables', "3\n");
    file_put_contents($file = $this->dir.'/dumps/shop.sql', $this->dump);
    $run = runImport($this->dir, DatabaseDriver::Mysql, $file);

    expect($run['line'])->toBe('orbit-import outcome=not_empty state=unchanged tables=3')
        ->and(DatabaseImport::outcome($run['line']))->toBe(['error_code' => 'database.not_empty', 'result' => ['database_state' => 'unchanged', 'tables' => 3]])
        ->and(file_exists($this->dir.'/state/loaded'))->toBeFalse();
});

it('refuses a symbolic link, a directory, a missing file and a file of another user before any login', function (string $case): void {
    file_put_contents($real = $this->dir.'/dumps/real.sql', $this->dump);
    $extra = [];
    $file = match ($case) {
        'symlink' => (static function (string $dir, string $real): string {
            symlink($real, $dir.'/dumps/link.sql');

            return $dir.'/dumps/link.sql';
        })($this->dir, $real),
        'symlinked folder' => (static function (string $dir): string {
            symlink($dir.'/dumps', $dir.'/elsewhere');

            return $dir.'/elsewhere/real.sql';
        })($this->dir),
        'directory' => (static function (string $dir): string {
            mkdir($dir.'/dumps/folder.sql');

            return $dir.'/dumps/folder.sql';
        })($this->dir),
        'missing' => $this->dir.'/dumps/missing.sql',
        // The Node's SSH user is someone else than the file's owner.
        'other owner' => (static function (string $dir) use (&$extra, $real): string {
            mkdir($dir.'/other');
            file_put_contents($dir.'/other/id', "#!/bin/sh\necho 0\n");
            chmod($dir.'/other/id', 0755);
            $extra = [$dir.'/other'];

            return $real;
        })($this->dir),
    };
    $run = runImport($this->dir, DatabaseDriver::Mysql, $file, $extra);

    expect($run['line'])->toBe('orbit-import outcome=file_refused state=unchanged')
        ->and(DatabaseImport::outcome($run['line'])['error_code'])->toBe('database.import_file_refused')
        ->and(file_exists($this->dir.'/state/passwords'))->toBeFalse();
})->with(['symlink', 'symlinked folder', 'directory', 'missing', 'other owner']);

it('names a partly filled MySQL database and keeps the error message out', function (): void {
    file_put_contents($this->dir.'/state/fail', "ERROR 1146 (42S02) at line 7: Table 'shop.secret_customer_row' doesn't exist\n");
    file_put_contents($this->dir.'/state/tables-after', "2\n");
    file_put_contents($file = $this->dir.'/dumps/shop.sql', $this->dump);
    $run = runImport($this->dir, DatabaseDriver::Mysql, $file);

    expect($run['line'])->toBe('orbit-import outcome=failed state=partial exit=1 bytes='.filesize($file).' tables=2 error=ERROR 1146 (42S02) at line 7')
        ->and(DatabaseImport::outcome($run['line']))->toBe(['error_code' => 'database.import_failed', 'result' => [
            'database_state' => 'partial', 'exit_code' => 1, 'bytes_read' => filesize($file), 'tables' => 2, 'error' => 'ERROR 1146 (42S02) at line 7']]);
});

it('names an empty PostgreSQL database after a failed single transaction', function (): void {
    file_put_contents($this->dir.'/state/fail', "psql:<stdin>:12: ERROR:  42501\n");
    file_put_contents($this->dir.'/state/tables-after', "0\n");
    file_put_contents($file = $this->dir.'/dumps/shop.sql', $this->dump);
    $run = runImport($this->dir, DatabaseDriver::Pgsql, $file);

    expect($run['line'])->toBe('orbit-import outcome=failed state=empty exit=1 bytes='.filesize($file).' tables=0 error=ERROR 42501 at line 12');
});

it('leaves the state unknown when the run ends without its result line', function (): void {
    expect(DatabaseImport::outcome(''))->toBe(['error_code' => 'database.import_failed', 'result' => ['database_state' => 'unknown']]);
});

it('keeps dot parts, links in the name and other characters out at the Gateway', function (string $path): void {
    expect(fn () => DatabaseImport::path($path))->toThrow(ResourceOperationException::class);
})->with(['shop.sql', '/a/../b.sql', '/a/./b.sql', '/a//b.sql', '/a/b.sql.zip', "/a/b\n.sql", '/a/$(x).sql']);
