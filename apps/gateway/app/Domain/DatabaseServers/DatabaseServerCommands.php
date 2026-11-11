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
    public function render(array $input): array
    {
        $driver = DatabaseDriver::from($input['driver']);
        $name = $input['container'];
        // Docker owns the probe; the wait reads its health status, so it ends at the first healthy report.
        $wait = 'until [ "$(docker inspect --format "{{.State.Health.Status}}" "$1")" = healthy ]; do sleep 1; done';

        return [
            'acl_path' => $input['volume'] === null ? '' : "/var/lib/docker/volumes/{$input['volume']}/_data/users.acl",
            // A rerun keeps an existing container and continues from it; the first start reads its password from stdin.
            'start' => ['sh', '-c', 'name=$1; shift; docker container inspect "$name" >/dev/null 2>&1 || exec "$@"', 'sh', $name,
                ...$this->run($input, $input['initialize'] ? $input['spec'] : $input['scrub_spec'], $input['initialize'])],
            // Q-WAIT: one bounded wait. The probe uses TCP, which MySQL and PostgreSQL accept only after initialization.
            'wait' => ['timeout', '60', 'sh', '-c', $wait, 'sh', $name],
            'login_client' => DatabaseStatements::login($driver, $name, match ($driver) {
                DatabaseDriver::Pgsql => 'postgres', DatabaseDriver::Redis => 'default', default => 'root'
            }, $driver === DatabaseDriver::Pgsql ? 'postgres' : 'mysql'),
            // The final container runs without the password variable on the same volume; the step waits for it again.
            'scrub' => ['timeout', '180', 'sh', '-c', 'name=$1; wait=$2; shift 2; docker rm --force "$name" >/dev/null && "$@" >/dev/null && exec timeout 60 sh -c "$wait" sh "$name"',
                'sh', $name, $wait, ...$this->run($input, $input['scrub_spec'], false)],
        ];
    }

    /** @return list<string> */
    public static function healthcheckArguments(DatabaseDriver $driver): array
    {
        $probe = match ($driver) {
            DatabaseDriver::Pgsql => 'pg_isready --host=127.0.0.1 --username=postgres --dbname=postgres',
            DatabaseDriver::Redis => 'valkey-cli PING',
            default => 'mysqladmin --protocol=TCP --host=127.0.0.1 ping --silent',
        };

        return ['--health-cmd', $probe, '--health-interval', '2s', '--health-timeout', '5s',
            '--health-retries', '3', '--health-start-period', '300s'];
    }

    /**
     * @param  array{driver: string, process_id: int, container: string, container_input: array<string, mixed>}  $input
     * @return list<string>
     */
    private function run(array $input, string $spec, bool $firstStart): array
    {
        $definition = $input['container_input'];
        $argv = ['docker', 'run', '--detach', '--name', $input['container'],
            '--label', 'orbit.managed=true', '--label', 'orbit.container.kind=process',
            '--label', 'orbit.process.id='.$input['process_id'], '--label', 'orbit.process.spec='.$spec,
            '--restart', ValidatedData::string($definition['restart']),
            ...ValidatedData::stringList($definition['healthcheck_arguments'])];
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
        if ($firstStart) {
            array_push($argv, '--env-file', '/dev/stdin');
        }

        return [...$argv, ValidatedData::string($definition['image']), ...ValidatedData::stringList($definition['command'])];
    }
}
