<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\DatabaseServers\CreateDatabaseServerRequest;
use Orbit\Sdk\Responses\DatabaseServers\DatabaseServerResponse;

final class CreateDatabaseServerCommand extends DatabaseCommand
{
    public const string TAG_PATTERN = '/\A[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}\z/D';

    #[\Override]
    protected $signature = 'database:server:create
        {slug : Database server slug}
        {--node= : Node ID or registered name}
        {--driver= : Server driver: mysql, redis, or pgsql. Defaults to mysql}
        {--process= : Existing Valkey Process ID to adopt}
        {--connection= : Existing redis connection for adoption}
        {--tag= : Image tag. Defaults to 8.4 for mysql or 8.1 for redis or 18 for pgsql}
        {--port= : Port to publish on the Node private addresses. Defaults to 3306 for mysql or 6379 for redis or 5432 for pgsql}
        {--lan-node=* : Explicit LAN peer Node ID or registered name (repeatable)}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Run a MySQL, PostgreSQL, or Valkey server as a Docker Node Process and keep its admin password in the Gateway.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $slug = $this->serverSlug();

        if ($slug === null) {
            return self::FAILURE;
        }

        $driver = $this->stringOption('driver');
        if ($driver !== null && ! in_array($driver, ['mysql', 'redis', 'pgsql'], true)) {
            return $this->renderGatewayFailure('database.driver_invalid', 'Server driver must be mysql, redis, or pgsql.');
        }

        $process = $this->option('process');
        $processId = null;
        if ($process !== null) {
            $processId = filter_var($process, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (! is_int($processId)) {
                return $this->renderGatewayFailure('database.process_invalid', 'Process ID must be a positive integer.');
            }
        }

        $tag = $this->stringOption('tag');

        if ($tag !== null && preg_match(self::TAG_PATTERN, $tag) !== 1) {
            return $this->renderGatewayFailure('database.server_tag_invalid', 'Tag must be an image tag such as 8.4 or 8.1.');
        }

        $port = $this->option('port');
        $parsedPort = null;

        if ($port !== null) {
            $parsedPort = filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);

            if (! is_int($parsedPort)) {
                return $this->renderGatewayFailure('database.port_invalid', 'Port must be an integer from 1 through 65535.');
            }
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $nodeId = $this->resolveNodeId($connector, $this->option('node'));

        if ($nodeId === null) {
            return self::FAILURE;
        }

        $lanNodeIds = [];
        foreach ($this->option('lan-node') as $selector) {
            $peerId = $this->resolveNodeId($connector, $selector);
            if ($peerId === null) {
                return self::FAILURE;
            }
            $lanNodeIds[] = $peerId;
        }
        $server = $this->sendWithProgress(
            $connector,
            new CreateDatabaseServerRequest(
                lanNodeIds: $lanNodeIds === [] ? null : array_values(array_unique($lanNodeIds)),
                slug: $slug,
                nodeId: $nodeId,
                tag: $tag,
                port: $parsedPort,
                driver: $driver,
                processId: $processId,
                connection: $this->stringOption('connection'),
            ),
            DatabaseServerResponse::class,
            ['Create Database server', 'Creating Database server', 'Created Database server'],
        );

        if (! $server instanceof DatabaseServerResponse) {
            return self::FAILURE;
        }

        return $this->renderServer($server, "Database server [{$server->slug}] is running.");
    }
}
