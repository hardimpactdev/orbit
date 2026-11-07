<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\DatabaseServers\UpdateDatabaseServerRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class UpdateDatabaseServerCommand extends DatabaseCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'database:server:update
        {slug : Database server slug}
        {--require-password : Require a password for the default user}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Require a password for the default user on an adopted Valkey server.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        if ($this->option('require-password') !== true) {
            return $this->renderGatewayFailure('database.require_password_required', 'Use --require-password to require a password for the default user.');
        }

        $slug = $this->serverSlug();

        if ($slug === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $profile = $this->activeGatewayProfile($repository);
        if ($profile === null) {
            return self::FAILURE;
        }
        $operation = $this->send($connector, new UpdateDatabaseServerRequest($slug, true, is_string($this->option('idempotency-key')) ? $this->option('idempotency-key') : null), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, "Update Database server [{$slug}]", 'Database server')
            : self::FAILURE;
    }
}
