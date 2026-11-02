<?php

declare(strict_types=1);

namespace App\Commands\Projects;

use App\Commands\Concerns\FollowsOperations;
use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Projects\InspectProjectMaintenanceRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class InspectProjectMaintenanceCommand extends GatewayCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'project:maintenance:inspect
        {project : Numeric project ID}
        {--open : Open the update group for the eligible updates}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return the final operation as machine-readable JSON}';

    #[\Override]
    protected $description = 'Inspect the default branch for dependency updates and advisories.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $projectId = $this->positiveId('project', 'Project', 'project.id_invalid');

        if ($projectId === null) {
            return self::FAILURE;
        }

        $profile = $this->activeGatewayProfile($repository);
        if ($profile === null) {
            return self::FAILURE;
        }
        $connector = $connectors->make($profile);
        $key = $this->option('idempotency-key');
        $request = new InspectProjectMaintenanceRequest($projectId, $this->option('open') === true, is_string($key) ? $key : null);
        $operation = $this->send($connector, $request, OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, "Inspect Project [{$projectId}]", 'Inspection')
            : self::FAILURE;
    }
}
