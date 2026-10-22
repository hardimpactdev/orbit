<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** @param Closure(Closure(string): string, string): void $test */
function postgres_statements_server(Closure $test): void
{
    $bin = rtrim(getenv('ORBIT_POSTGRES18_BIN') ?: '/usr/lib/postgresql/18/bin', '/').'/';
    if (! is_executable($bin.'initdb')) {
        if (getenv('CI')) {
            test()->fail('The CI runner requires PostgreSQL 18 binaries.');
        }
        test()->markTestSkipped('PostgreSQL 18 binaries are unavailable.');
    }
    $directory = sys_get_temp_dir().'/orbit-pg-statements-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $run = static function (array $arguments, ?string $input = null) use ($directory): string {
        $process = new Process($arguments, env: ['PGHOST' => $directory, 'PGPORT' => '5432', 'PGUSER' => 'orbit_test', 'PGDATABASE' => 'postgres']);
        $process->setInput($input);
        $process->mustRun();

        return trim($process->getOutput());
    };
    $psql = static fn (string $sql): string => $run([$bin.'psql', '-X', '-A', '-t', '-q', '-v', 'ON_ERROR_STOP=1'], $sql);
    $started = false;
    try {
        $run([$bin.'initdb', '-D', $directory.'/data', '-U', 'orbit_test', '--auth=trust', '--no-locale', '--encoding=UTF8', '--no-sync']);
        $run([$bin.'pg_ctl', '-D', $directory.'/data', '-l', $directory.'/log', '-o', '-F -k '.$directory." -h '' -c wal_level=logical -c max_prepared_transactions=10", '-w', 'start']);
        $started = true;
        $test($psql, $directory);
    } finally {
        if ($started) {
            $run([$bin.'pg_ctl', '-D', $directory.'/data', '-m', 'immediate', '-w', 'stop']);
        }
        (new Filesystem)->deleteDirectory($directory);
    }
}
