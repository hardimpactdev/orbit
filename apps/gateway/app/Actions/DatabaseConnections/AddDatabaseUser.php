<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Contracts\RemoteShell;
use App\Enums\Processes\ProcessRuntime;
use App\Models\DatabaseConnection;
use App\Models\Node;
use App\Models\Process;
use App\Services\DatabaseConnections\DatabaseConnectionRegistry;
use App\Services\DatabaseConnections\DatabaseConnectionRegistryFailure;

final readonly class AddDatabaseUser
{
    private const string IdentifierPattern = '/^[A-Za-z0-9_]{1,64}$/';

    public function __construct(
        private RemoteShell $remoteShell,
        private DatabaseConnectionRegistry $registry,
    ) {}

    public function resolveProcess(string $service, ?Node $node = null): Process|DatabaseConnectionRegistryFailure
    {
        $query = Process::query()
            ->with(['node', 'owner'])
            ->where('name', $service)
            ->withRuntimeService('mysql');

        if ($node instanceof Node) {
            $query->where('node_id', $node->id);
        }

        $processes = $query->get();

        if ($processes->isEmpty()) {
            return DatabaseConnectionRegistryFailure::validation(
                'service',
                $service,
                "Managed MySQL process '{$service}' was not found.",
                [
                    'service' => $service,
                    'node' => $node?->name,
                ],
            );
        }

        if ($processes->count() > 1) {
            return DatabaseConnectionRegistryFailure::validation(
                'service',
                $service,
                "Managed MySQL process '{$service}' exists on multiple nodes. Use --node.",
                [
                    'service' => $service,
                    'nodes' => $processes
                        ->map(fn (Process $process): ?string => $process->node?->name)
                        ->filter()
                        ->values()
                        ->all(),
                ],
            );
        }

        return $processes->first();
    }

    public function handle(
        Process $process,
        string $connection,
        string $database,
        string $username,
        string $password,
    ): DatabaseConnection|DatabaseConnectionRegistryFailure {
        $validation =
            $this->validateProcess($process) ?? $this->validateIdentifier(
                'database',
                $database,
            ) ?? $this->validateIdentifier('username', $username);

        if ($validation instanceof DatabaseConnectionRegistryFailure) {
            return $validation;
        }

        $endpoint = $this->endpoint($process);

        if ($endpoint instanceof DatabaseConnectionRegistryFailure) {
            return $endpoint;
        }

        $converged = $this->converge($process, $database, $username, $password);

        if ($converged instanceof DatabaseConnectionRegistryFailure) {
            return $converged;
        }

        $payload = [
            'node_id' => $process->node_id,
            'driver' => 'mysql',
            'host' => $endpoint['host'],
            'port' => $endpoint['port'],
            'database' => $database,
            'username' => $username,
            'password' => $password,
        ];

        $existing = $this->registry->show($connection);

        if (
            $existing instanceof DatabaseConnectionRegistryFailure
            && $existing->code === 'database_connection.not_found'
        ) {
            return $this->registry->create($connection, $payload);
        }

        if ($existing instanceof DatabaseConnectionRegistryFailure) {
            return $existing;
        }

        return $this->registry->update($connection, $payload);
    }

    private function validateProcess(Process $process): ?DatabaseConnectionRegistryFailure
    {
        $config = is_array($process->runtime_config) ? $process->runtime_config : [];

        if (($config['service'] ?? null) !== 'mysql') {
            return DatabaseConnectionRegistryFailure::validation(
                'service',
                $process->name,
                "Process '{$process->name}' is not a managed MySQL process.",
                [
                    'service' => $config['service'] ?? null,
                    'process' => $process->name,
                ],
            );
        }

        if ($process->runtime !== ProcessRuntime::Docker) {
            return DatabaseConnectionRegistryFailure::validation(
                'runtime',
                $process->runtime->value,
                'database:add-user currently supports Docker managed MySQL processes only.',
                [
                    'service' => $process->name,
                    'runtime' => $process->runtime->value,
                ],
            );
        }

        if (! $process->node instanceof Node) {
            return DatabaseConnectionRegistryFailure::validation(
                'node',
                null,
                "Process '{$process->name}' has no serving node.",
                [
                    'service' => $process->name,
                ],
            );
        }

        return null;
    }

    private function validateIdentifier(string $field, string $value): ?DatabaseConnectionRegistryFailure
    {
        if (preg_match(self::IdentifierPattern, $value) === 1) {
            return null;
        }

        return DatabaseConnectionRegistryFailure::validation(
            $field,
            $value,
            "MySQL {$field} must use only letters, digits, and underscores, and be at most 64 characters.",
            [
                'field' => $field,
            ],
        );
    }

    /**
     * @return array{host: string, port: int}|DatabaseConnectionRegistryFailure
     */
    private function endpoint(Process $process): array|DatabaseConnectionRegistryFailure
    {
        $config = is_array($process->runtime_config) ? $process->runtime_config : [];
        $endpoint = is_array($config['endpoint'] ?? null) ? $config['endpoint'] : [];
        $host = $endpoint['host'] ?? null;
        $port = $endpoint['port'] ?? null;

        if (! is_string($host) || trim($host) === '' || ! is_int($port) && ! is_numeric($port)) {
            return DatabaseConnectionRegistryFailure::validation(
                'service',
                $process->name,
                "Managed MySQL process '{$process->name}' is missing endpoint metadata.",
                [
                    'service' => $process->name,
                ],
            );
        }

        return [
            'host' => trim($host),
            'port' => (int) $port,
        ];
    }

    private function converge(
        Process $process,
        string $database,
        string $username,
        string $password,
    ): ?DatabaseConnectionRegistryFailure {
        $node = $process->node;

        if (! $node instanceof Node) {
            return DatabaseConnectionRegistryFailure::validation(
                'node',
                null,
                "Process '{$process->name}' has no serving node.",
                [
                    'service' => $process->name,
                ],
            );
        }

        $container = $this->containerName($process);

        if ($container instanceof DatabaseConnectionRegistryFailure) {
            return $container;
        }

        $result = $this->remoteShell->run($node, $this->convergenceScript($container), [
            'input' => $this->sql($database, $username, $password),
            'metadata' => [
                'operation' => 'database.add-user',
                'process' => $process->name,
            ],
            'strict' => true,
            'timeout' => 120,
        ]);

        if ($result->successful()) {
            return null;
        }

        return DatabaseConnectionRegistryFailure::validation(
            'service',
            $process->name,
            "Could not add MySQL user '{$username}' on managed process '{$process->name}'.",
            [
                'service' => $process->name,
                'exit_code' => $result->exitCode,
                'stderr' => trim($result->stderr),
            ],
        );
    }

    private function containerName(Process $process): string|DatabaseConnectionRegistryFailure
    {
        $name = trim($process->name);

        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $name) === 1) {
            return $name;
        }

        return DatabaseConnectionRegistryFailure::validation(
            'service',
            $process->name,
            "Managed MySQL process '{$process->name}' cannot be used as a Docker container name.",
            [
                'service' => $process->name,
            ],
        );
    }

    private function convergenceScript(string $container): string
    {
        return sprintf(
            <<<'SH'
                container=%s
                if ! docker inspect "$container" >/dev/null 2>&1; then
                  echo "Managed MySQL container '$container' was not found." >&2
                  exit 67
                fi

                ready=0
                for attempt in $(seq 1 30); do
                  if docker exec "$container" sh -lc 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqladmin ping -uroot --silent' >/dev/null 2>&1; then
                    ready=1
                    break
                  fi
                  sleep 2
                done

                if [ "$ready" != 1 ]; then
                  echo "Managed MySQL container '$container' did not become ready." >&2
                  exit 68
                fi

                docker exec -i "$container" sh -lc 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot'
                SH,
            escapeshellarg($container),
        );
    }

    private function sql(string $database, string $username, string $password): string
    {
        return implode(PHP_EOL, [
            'CREATE DATABASE IF NOT EXISTS '
                .$this->identifier($database)
                .' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
            'CREATE USER IF NOT EXISTS '
                .$this->stringLiteral($username)
                ."@'%' IDENTIFIED BY "
                .$this->stringLiteral($password)
                .';',
            'ALTER USER '.$this->stringLiteral($username)."@'%' IDENTIFIED BY ".$this->stringLiteral($password).';',
            'GRANT ALL PRIVILEGES ON '.$this->identifier($database).'.* TO '.$this->stringLiteral($username)."@'%';",
            'FLUSH PRIVILEGES;',
            '',
        ]);
    }

    private function identifier(string $value): string
    {
        return '`'.$value.'`';
    }

    private function stringLiteral(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "''"], $value)."'";
    }
}
