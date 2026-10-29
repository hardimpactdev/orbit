<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\DatabaseConnections\DestroyDatabaseConnectionRequest;
use Orbit\Sdk\Responses\DatabaseConnections\DestroyedDatabaseConnectionResponse;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class DestroyDatabaseConnectionCommand extends DatabaseCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'database:destroy
        {slug : Database connection slug}
        {--force : Skip the destructive confirmation prompt}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Destroy a Database connection.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $slug = $this->slug();

        if ($slug === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        if (! $this->confirmAction(
            "Destroy Database connection record [{$slug}]? A registered database stays. A database on a Database server is dropped with its user.",
            'Database connection destruction cancelled.',
            option: 'force',
            requiredCode: 'database.confirmation_required',
            requiredMessage: 'Use --force to confirm Database connection destruction.',
        )) {
            return self::FAILURE;
        }

        $result = $this->send($connector, new DestroyDatabaseConnectionRequest($slug,
            is_string($this->option('idempotency-key')) ? $this->option('idempotency-key') : null), DestroyedDatabaseConnectionResponse::class);
        if (! $result instanceof DestroyedDatabaseConnectionResponse) {
            return self::FAILURE;
        }
        if ($result->result instanceof OperationResponse) {
            $profile = $this->activeGatewayProfile($repository);

            return $profile === null ? self::FAILURE
                : $this->followOperation($connector, $profile, $result->result, "Destroy database [{$slug}]", 'Database');
        }

        return $this->renderConnection($result->result, "Database connection [{$slug}] destroyed.");
    }
}
