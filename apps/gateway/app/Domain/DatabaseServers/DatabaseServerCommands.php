<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Support\ValidatedData;

/**
 * The docker commands of database:server:create, one per step as official SSH argv (flow-tighten rule 22, script
 * audit). Rendered from the admitted container definition, so the run builds nothing and the intent shows what runs.
 */
final readonly class DatabaseServerCommands
{
    /**
     * @param  array{driver: string, process_id: int, container: string, container_input: array<string, mixed>, spec: string, scrub_spec: string, initialize: bool, volume: ?string}  $input
     * @return array<string, list<string>|string>
     */
    public function render(array $input, int $operationId): array
    {
        $driver = DatabaseDriver::from($input['driver']);
        $name = $input['container'];
        $firstLogin = "/run/orbit-database-server-create-{$operationId}-first.env";
        $clientLogin = "/run/orbit-database-server-create-{$operationId}.env";

        return [
            'first_login' => $firstLogin,
            'client_login' => $clientLogin,
            'volume_create' => $input['volume'] === null ? [] : ['docker', 'volume', 'create', $input['volume']],
            'acl_path' => $input['volume'] === null ? '' : "/var/lib/docker/volumes/{$input['volume']}/_data/users.acl",
            // --force also removes a running container; the data volume keeps the data and the password.
            'remove_old' => ['docker', 'rm', '--force', $name],
            'run_first' => $this->run($input, $input['initialize'] ? $input['spec'] : $input['scrub_spec'], $input['initialize'] ? $firstLogin : null),
            // Q-WAIT: one bounded probe loop. MySQL and PostgreSQL accept TCP only after first-start initialization.
            'wait' => ['timeout', '60', 'docker', 'exec', $name, 'sh', '-c', 'until '.self::probe($driver).' >/dev/null 2>&1; do sleep 1; done'],
            'stop' => ['docker', 'stop', '--time', '60', $name],
            'remove' => ['docker', 'rm', $name],
            'run_final' => $this->run($input, $input['scrub_spec'], null),
            'login_client' => DatabaseStatements::ownerClient($driver, $name, $clientLogin, match ($driver) {
                DatabaseDriver::Pgsql => 'postgres', DatabaseDriver::Redis => 'default', default => 'root'
            }, 'postgres'),
            'login' => $driver === DatabaseDriver::Redis ? "PING\n" : "SELECT 1;\n",
        ];
    }

    private static function probe(DatabaseDriver $driver): string
    {
        return match ($driver) {
            DatabaseDriver::Pgsql => 'pg_isready --host=127.0.0.1 --username=postgres --dbname=postgres',
            DatabaseDriver::Redis => 'valkey-cli PING',
            default => 'mysqladmin --protocol=TCP --host=127.0.0.1 ping --silent',
        };
    }

    /**
     * @param  array{driver: string, process_id: int, container: string, container_input: array<string, mixed>}  $input
     * @return list<string>
     */
    private function run(array $input, string $spec, ?string $envFile): array
    {
        $definition = $input['container_input'];
        $argv = ['docker', 'run', '--detach', '--name', $input['container'],
            '--label', 'orbit.managed=true', '--label', 'orbit.container.kind=process',
            '--label', 'orbit.process.id='.$input['process_id'], '--label', 'orbit.process.spec='.$spec,
            '--restart', ValidatedData::string($definition['restart']), '--health-cmd', self::probe(DatabaseDriver::from($input['driver'])),
            '--health-interval', '2s', '--health-timeout', '5s', '--health-retries', '3', '--health-start-period', '300s'];
        foreach (ValidatedData::stringList($definition['ports']) as $port) {
            array_push($argv, '--publish', $port);
        }
        foreach ((array) $definition['mounts'] as $item) {
            $mount = ValidatedData::object($item);
            $source = ValidatedData::string($mount['source']);
            $kind = str_starts_with($source, '/') ? 'bind' : 'volume';
            $target = ValidatedData::string($mount['target']);
            array_push($argv, '--mount', "type={$kind},source={$source},target={$target}".($mount['read_only'] === true ? ',readonly' : ''));
        }
        $workdir = ValidatedData::nullableString($definition['working_directory'] ?? null);
        if ($workdir !== null) {
            array_push($argv, '--workdir', $workdir);
        }
        if ($envFile !== null) {
            array_push($argv, '--env-file', $envFile);
        }

        return [...$argv, ValidatedData::string($definition['image']), ...ValidatedData::stringList($definition['command'])];
    }
}
