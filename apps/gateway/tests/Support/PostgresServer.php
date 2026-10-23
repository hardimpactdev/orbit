<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\InputStream;
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
    $server = $client = $input = null;
    $psql = static function (string $sql) use ($bin, $directory, &$client, &$input): string {
        if ($client === null || $input === null || ! $client->isRunning()) {
            $input = new InputStream;
            $client = new Process([$bin.'psql', '-X', '-A', '-t', '-q', '-v', 'ON_ERROR_STOP=1'], env: [
                'PGHOST' => $directory, 'PGPORT' => '5432', 'PGUSER' => 'orbit_test', 'PGDATABASE' => 'postgres',
            ]);
            $client->setInput($input);
            $client->start();
        }
        $marker = 'orbit_query_'.bin2hex(random_bytes(12));
        $input->write($sql."\n\\connect postgres\n\\echo {$marker}\n");
        $output = '';
        $acknowledged = false;
        foreach ($client->getIterator(Process::ITER_KEEP_OUTPUT) as $type => $data) {
            if ($type === Process::OUT) {
                $output .= $data;
            }
            if (str_ends_with($output, $marker."\n")) {
                $acknowledged = true;
                break;
            }
        }
        if (! $acknowledged && ! $client->isSuccessful()) {
            throw new ProcessFailedException($client);
        }
        $client->clearOutput();
        $client->clearErrorOutput();

        return trim($acknowledged ? substr($output, 0, -strlen($marker) - 1) : $output);
    };
    try {
        // Clone only a never-started seed; each test still gets an independent cluster.
        static $seed = null;
        if ($seed === null) {
            $seed = sys_get_temp_dir().'/orbit-pg-seed-'.bin2hex(random_bytes(8));
            $owner = getmypid();
            register_shutdown_function(static function () use ($seed, $owner): void {
                if (getmypid() === $owner) {
                    (new Filesystem)->deleteDirectory($seed);
                }
            });
            $run([$bin.'initdb', '-D', $seed, '-U', 'orbit_test', '--auth=trust', '--no-locale', '--encoding=UTF8', '--no-sync']);
        }
        (new Filesystem)->copyDirectory($seed, $directory.'/data');
        chmod($directory.'/data', 0700);
        $server = new Process([
            $bin.'postgres', '-D', $directory.'/data', '-F', '-k', $directory, '-h', '',
            '-c', 'wal_level=logical', '-c', 'max_prepared_transactions=10',
        ]);
        $server->start();
        $log = '';
        if (! $server->waitUntil(static function (string $type, string $data) use (&$log): bool {
            $log .= $data;

            return str_contains($log, 'database system is ready to accept connections');
        })) {
            throw new ProcessFailedException($server);
        }
        $test($psql, $directory);
    } finally {
        $input?->close();
        $client?->stop(0);
        if ($server?->isRunning()) {
            $server->signal(SIGQUIT);
            $server->wait();
        }
        (new Filesystem)->deleteDirectory($directory);
    }
}
