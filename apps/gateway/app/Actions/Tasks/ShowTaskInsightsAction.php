<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\TaskInsightsData;
use App\Domain\Tasks\TaskInsights;
use Carbon\CarbonImmutable;

final readonly class ShowTaskInsightsAction
{
    public function __construct(private RequireTasksExtensionAction $requireExtension, private TaskInsights $insights) {}

    /** @param  7|30  $days */
    public function execute(int $days, ?int $projectId): TaskInsightsData
    {
        $this->requireExtension->execute();

        return $this->insights->report($days, $projectId, CarbonImmutable::now());
    }
}
