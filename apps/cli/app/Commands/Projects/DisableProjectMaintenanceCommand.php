<?php

declare(strict_types=1);

namespace App\Commands\Projects;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Projects\DisableProjectMaintenanceRequest;
use Orbit\Sdk\Responses\Projects\ProjectResponse;

final class DisableProjectMaintenanceCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'project:maintenance:disable
        {project : Numeric project ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Stop dependency maintenance for a Project.';

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
            new DisableProjectMaintenanceRequest($projectId),
            ProjectResponse::class,
            ['Disable maintenance', 'Disabling maintenance', 'Disabled maintenance'],
        );

        if (! $project instanceof ProjectResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($project->toArray());

            return self::SUCCESS;
        }

        $this->writeHumanMessage("Dependency maintenance is off for Project [{$project->slug}].");
        $this->writeHumanMessage("Request ID: {$project->requestId}");

        return self::SUCCESS;
    }
}
