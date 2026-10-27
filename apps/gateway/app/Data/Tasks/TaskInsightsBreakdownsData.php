<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class TaskInsightsBreakdownsData extends Data
{
    /**
     * @param  list<TaskInsightsBreakdownRowData>  $model  By implementer model.
     * @param  list<TaskInsightsBreakdownRowData>  $project
     * @param  list<TaskInsightsBreakdownRowData>  $release  By the last verified Gateway release that began before each task started.
     */
    public function __construct(
        public array $model,
        public array $project,
        public array $release,
    ) {}
}
