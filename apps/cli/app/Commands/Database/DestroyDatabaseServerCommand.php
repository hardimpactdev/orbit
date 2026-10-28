<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\DatabaseServers\DestroyDatabaseServerRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class DestroyDatabaseServerCommand extends DatabaseCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'database:server:destroy
        {slug : Database server slug}
        {--force : Skip the destructive confirmation prompt}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove a Database server that no connection uses. Its data volume stays on the Node.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $slug = $this->serverSlug();

        if ($slug === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        if (! $this->confirmAction(
            "Destroy Database server [{$slug}] and its Process? The data volume stays on the Node.",
            'Database server destruction cancelled.',
            option: 'force',
            requiredCode: 'database.confirmation_required',
            requiredMessage: 'Use --force to confirm Database server destruction.',
        )) {
            return self::FAILURE;
        }

        $profile = $this->activeGatewayProfile($repository);
        if ($profile === null) {
            return self::FAILURE;
        }
        $operation = $this->send($connector, new DestroyDatabaseServerRequest($slug, is_string($this->option('idempotency-key')) ? $this->option('idempotency-key') : null), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, "Destroy Database server [{$slug}]", 'Database server')
            : self::FAILURE;
    }
}
