<?php

declare(strict_types=1);

namespace App\Commands\Projects;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Projects\EnableProjectReviewAndMergeRequest;
use Orbit\Sdk\Responses\Projects\ProjectResponse;

final class EnableProjectReviewAndMergeCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'project:review-and-merge:enable
        {project : Numeric project ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Review every push and incoming pull request, and merge reviewed green heads.';

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
            new EnableProjectReviewAndMergeRequest($projectId),
            ProjectResponse::class,
            ['Enable review and merge', 'Enabling review and merge', 'Enabled review and merge'],
        );

        if (! $project instanceof ProjectResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($project->toArray());

            return self::SUCCESS;
        }

        $this->writeHumanMessage("Review and merge is on, after {$project->mergeCheck} passes for Project [{$project->slug}].");
        $this->writeHumanMessage("Request ID: {$project->requestId}");

        return self::SUCCESS;
    }
}
