<?php

declare(strict_types=1);

namespace App\Infrastructure\DatabaseServers;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Infrastructure\Shared\StoredValue;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Process;
use SensitiveParameter;

/**
 * Runs the fixed MySQL or PostgreSQL admin client inside the server's container through the agent's
 * `databases.server-admin` (docs/reference/database-servers.md). The root password travels in the protected
 * envelope and reaches the client through its environment.
 */
final readonly class RemoteDatabaseServerAdmin implements DatabaseServerAdmin
{
    public function __construct(
        private AgentCommandRunner $agents,
        private DockerProcessRenderer $docker,
    ) {}

    public function waitUntilReady(DatabaseServer $server, int $timeoutSeconds): bool
    {
        [$variable, $client] = $this->client($server, tcp: true);

        try {
            $this->run($server, ['operation' => 'ready', 'password_variable' => $variable, 'client' => $client, 'timeout_seconds' => $timeoutSeconds], (float) ($timeoutSeconds + 60));
        } catch (AgentCommandRefused) {
            return false;
        }

        return true;
    }

    public function execute(DatabaseServer $server, #[SensitiveParameter] string $sql): array
    {
        [$variable, $client] = $this->client($server);

        try {
            $result = $this->run($server, ['operation' => 'execute', 'password_variable' => $variable, 'client' => $client, 'sql' => $sql]);
        } catch (AgentCommandRefused|AgentRunnerFailure) {
            throw $this->failed($server);
        }

        $rows = [];

        foreach (preg_split('/\R/', StoredValue::string($result['output'] ?? null)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            $rows[] = explode("\t", $line, 2)[0];
        }

        return $rows;
    }

    public function copyDatabase(DatabaseServer $server, string $source, string $target): void
    {
        if ($server->driver !== DatabaseDriver::Mysql) {
            throw new ResourceOperationException('database.driver_unsupported', 'Instance database cloning requires MySQL.', 422);
        }
        foreach ([$source, $target] as $database) {
            if (preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $database) !== 1) {
                throw $this->failed($server);
            }
        }

        try {
            $this->run($server, ['operation' => 'copy', 'source' => $source, 'target' => $target]);
        } catch (AgentCommandRefused|AgentRunnerFailure) {
            throw $this->failed($server);
        }
    }

    /** @return array{string, list<string>} The password variable and the client arguments. */
    private function client(DatabaseServer $server, bool $tcp = false): array
    {
        if ($server->driver === DatabaseDriver::Pgsql) {
            return ['PGPASSWORD', ['psql', '--no-psqlrc', '--host=127.0.0.1', '--username=postgres', '--dbname=postgres', '--no-align', '--tuples-only', '--quiet', '--set=ON_ERROR_STOP=1']];
        }

        return ['MYSQL_PWD', ['mysql', ...($tcp ? ['--protocol=TCP', '--host=127.0.0.1'] : []), '--user=root', '--batch', '--skip-column-names']];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function run(DatabaseServer $server, array $input, ?float $deadlineSeconds = null): array
    {
        $process = $server->process;

        if (! $process instanceof Process) {
            throw $this->failed($server);
        }

        return $this->agents->run($this->node($server), 'databases.server-admin', [
            ...$input,
            'container' => $this->docker->containerName($process),
            'password' => (string) $server->root_password,
        ], $deadlineSeconds);
    }

    private function node(DatabaseServer $server): Node
    {
        $node = $server->node;

        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            throw new ResourceOperationException(
                errorCode: 'process.wireguard_ip_missing',
                message: "Node [{$node->name}] has no WireGuard address.",
                status: 422,
            );
        }

        return $node;
    }

    private function failed(DatabaseServer $server): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'database.server_command_failed',
            message: "Database server [{$server->slug}] could not run an admin command.",
            status: 502,
        );
    }
}
