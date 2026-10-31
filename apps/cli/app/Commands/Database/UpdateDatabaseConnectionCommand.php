<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\DatabaseConnections\UpdateDatabaseConnectionRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class UpdateDatabaseConnectionCommand extends DatabaseCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'database:update
        {slug : Database connection slug}
        {--driver= : Driver: mysql, pgsql, sqlite, or redis}
        {--node= : Node ID or registered name; empty clears the association}
        {--host= : Hostname or IP for mysql and pgsql}
        {--port= : TCP port for mysql and pgsql}
        {--database= : Database name for mysql and pgsql}
        {--path= : Unix absolute sqlite path}
        {--username= : Username}
        {--password= : Password}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Update a Database connection.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $slug = $this->slug();

        if ($slug === null) {
            return self::FAILURE;
        }

        $driver = $this->input->getOption('driver');
        $node = $this->input->getOption('node');
        $host = $this->input->getOption('host');
        $port = $this->input->getOption('port');
        $database = $this->input->getOption('database');
        $path = $this->input->getOption('path');
        $username = $this->input->getOption('username');
        $password = $this->input->getOption('password');

        $hasDriver = $driver !== null;
        $hasNode = $node !== null;
        $hasHost = $host !== null;
        $hasPort = $port !== null;
        $hasDatabase = $database !== null;
        $hasPath = $path !== null;
        $hasUsername = $username !== null;
        $hasPassword = $password !== null;

        if (! $hasDriver && ! $hasNode && ! $hasHost && ! $hasPort && ! $hasDatabase && ! $hasPath && ! $hasUsername && ! $hasPassword) {
            return $this->renderGatewayFailure(
                'database.update_required',
                'Provide at least one Database connection update option.',
            );
        }

        if ($hasDriver && (! is_string($driver) || ! in_array($driver, self::DRIVERS, true))) {
            return $this->renderGatewayFailure(
                'database.driver_invalid',
                'Driver must be mysql, pgsql, sqlite, or redis.',
            );
        }

        $parsedPort = null;

        if ($hasPort) {
            $parsedPort = filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);

            if (! is_int($parsedPort)) {
                return $this->renderGatewayFailure('database.port_invalid', 'Port must be an integer from 1 through 65535.');
            }
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $nodeId = null;

        if ($hasNode && is_string($node) && $node !== '') {
            $nodeId = $this->resolveNodeId($connector, $node);

            if ($nodeId === null) {
                return self::FAILURE;
            }
        }

        $profile = $this->activeGatewayProfile($repository);
        if ($profile === null) {
            return self::FAILURE;
        }
        $operation = $this->send(
            $connector,
            new UpdateDatabaseConnectionRequest(
                slug: $slug,
                idempotencyKey: is_string($this->option('idempotency-key')) ? $this->option('idempotency-key') : null,
                hasDriver: $hasDriver,
                driver: is_string($driver) ? $driver : null,
                hasNodeId: $hasNode,
                nodeId: $nodeId,
                hasHost: $hasHost,
                host: is_string($host) && $host !== '' ? $host : null,
                hasPort: $hasPort,
                port: $parsedPort,
                hasDatabase: $hasDatabase,
                database: is_string($database) && $database !== '' ? $database : null,
                hasPath: $hasPath,
                path: is_string($path) && $path !== '' ? $path : null,
                hasUsername: $hasUsername,
                username: is_string($username) ? $username : null,
                hasPassword: $hasPassword,
                password: is_string($password) ? $password : null,
            ),
            OperationResponse::class,
        );

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, "Update Database connection [{$slug}]", 'Database connection')
            : self::FAILURE;
    }
}
