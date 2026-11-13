<?php

declare(strict_types=1);

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\DatabaseServerProcess;
use App\Domain\DatabaseServers\DatabaseStatements;
use App\Models\DatabaseServer;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/*
 * The postgres image's initdb trusts the socket and the loopback for every user, so a client in the container logs in
 * as anyone without a password. A new PostgreSQL server initializes with SCRAM for both instead.
 */

beforeEach(function (): void {
    $this->node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'db', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.12', 'public_ssh_host' => '10.44.0.12', 'user' => 'orbit',
    ]));
    $this->server = fn (string $driver): DatabaseServer => DatabaseServer::query()->create([
        'slug' => "new-{$driver}", 'driver' => $driver, 'node_id' => $this->node->id, 'tag' => $driver === 'pgsql' ? '18' : '8.4',
        'port' => 5433, 'status' => 'provisioning', 'root_password' => 'root-secret-sentinel',
    ]);
});

it('initializes a PostgreSQL server without trust for the socket or the loopback', function (): void {
    $server = ($this->server)('pgsql');

    expect(DatabaseServerProcess::firstStartEnvironment($server, 'root-secret-sentinel'))->toBe([
        'POSTGRES_PASSWORD' => 'root-secret-sentinel',
        'POSTGRES_INITDB_ARGS' => '--auth-local=scram-sha-256 --auth-host=scram-sha-256',
    ])->and(DatabaseServerProcess::data($server, $this->node, 'root-secret-sentinel')->environment)
        ->toBe(DatabaseServerProcess::firstStartEnvironment($server, 'root-secret-sentinel'))
        ->and(DatabaseServerProcess::firstStartEnvFile($server, 'root-secret-sentinel'))
        ->toBe("POSTGRES_PASSWORD=root-secret-sentinel\nPOSTGRES_INITDB_ARGS=--auth-local=scram-sha-256 --auth-host=scram-sha-256\n");
});

it('gives a MySQL server only its root password', function (): void {
    expect(DatabaseServerProcess::firstStartEnvFile(($this->server)('mysql'), 'root-secret-sentinel'))
        ->toBe("MYSQL_ROOT_PASSWORD=root-secret-sentinel\n");
});

/*
 * The real image with the exact env file: no trust line, no login without the password, and Orbit's own admin login
 * still works. It starts a throwaway postgres:18 container, so it runs only in the `privileged` group with
 * ORBIT_REAL_DATABASE_CLIENTS=1 on a host where that is allowed, and removes it afterwards:
 * `ORBIT_REAL_DATABASE_CLIENTS=1 vendor/bin/pest --group privileged --default-time-limit=300 <this file>`. CI does not run it.
 */
it('makes the real postgres:18 image ask every login for its password', function (): void {
    if (getenv('ORBIT_REAL_DATABASE_CLIENTS') !== '1') {
        $this->markTestSkipped('Set ORBIT_REAL_DATABASE_CLIENTS=1 on a host where throwaway database containers are allowed.');
    }
    $password = bin2hex(random_bytes(12));
    $name = 'orbit-first-start-'.bin2hex(random_bytes(4));
    $run = static function (array $argv, string $stdin = ''): array {
        $process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);

        return ['exit' => proc_close($process), 'out' => trim($out)];
    };
    try {
        expect($run(['sudo', '-n', 'docker', 'run', '--detach', '--pull=never', '--name', $name, '--env-file', '/dev/stdin', 'postgres:18'],
            DatabaseServerProcess::firstStartEnvFile(($this->server)('pgsql'), $password))['exit'])->toBe(0);
        for ($i = 0; $i < 120 && $run(['sudo', '-n', 'docker', 'exec', $name, 'pg_isready', '--host=127.0.0.1', '--username=postgres'])['exit'] !== 0; $i++) {
            sleep(1);
        }
        $trust = $run(['sudo', '-n', 'docker', 'exec', $name, 'sh', '-c', 'grep -v "^#" "$PGDATA/pg_hba.conf" | grep -c trust']);
        $socket = $run(['sudo', '-n', 'docker', 'exec', $name, 'psql', '-X', '-w', '--username=postgres', '-tAc', 'SELECT 1']);
        $loopback = $run(['sudo', '-n', 'docker', 'exec', $name, 'psql', '-X', '-w', '--host=127.0.0.1', '--username=postgres', '-tAc', 'SELECT 1']);
        $admin = $run(DatabaseStatements::login(DatabaseDriver::Pgsql, $name, 'postgres', 'postgres'), "{$password}\nSELECT current_user;\n");
        $wrong = $run(DatabaseStatements::login(DatabaseDriver::Pgsql, $name, 'postgres', 'postgres'), "wrong-{$password}\nSELECT current_user;\n");

        expect($trust['out'])->toBe('0')
            ->and($socket['exit'])->not->toBe(0)
            ->and($loopback['exit'])->not->toBe(0)
            ->and($admin)->toBe(['exit' => 0, 'out' => 'postgres'])
            ->and($wrong['exit'])->not->toBe(0);
    } finally {
        exec('sudo -n docker rm --force --volumes '.escapeshellarg($name).' >/dev/null 2>&1');
    }
})->group('privileged');
