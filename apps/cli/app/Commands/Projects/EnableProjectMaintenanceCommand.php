<?php

declare(strict_types=1);

namespace App\Commands\Projects;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Projects\EnableProjectMaintenanceRequest;
use Orbit\Sdk\Responses\Projects\ProjectResponse;

final class EnableProjectMaintenanceCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'project:maintenance:enable
        {project : Numeric project ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Inspect the default branch for dependency updates and advisories, and open update groups.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $projectId = $this->positiveId('project', 'Project', 'project.id_invalid');

        if ($projectId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $project = $this->sendWithProgress(
            $connector,
            new EnableProjectMaintenanceRequest($projectId),
            ProjectResponse::class,
            ['Enable maintenance', 'Enabling maintenance', 'Enabled maintenance'],
        );

        if (! $project instanceof ProjectResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($project->toArray());

            return self::SUCCESS;
        }

        $this->writeHumanMessage("Dependency maintenance is on for Project [{$project->slug}].");
        $this->writeHumanMessage("Request ID: {$project->requestId}");

        return self::SUCCESS;
    }
}
