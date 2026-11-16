<?php

declare(strict_types=1);

namespace App\Data\Ci;

use Spatie\LaravelData\Data;

/** The CI runner view (docs/reference/ci-runner-view.md#response): each CI Node with its runners, and the self-hosted jobs of the window. */
final class CiRunnersData extends Data
{
    /**
     * @param  list<CiNodeData>  $nodes
     * @param  list<CiRepositoryData>  $repositories
     * @param  list<CiJobData>  $jobs
     */
    public function __construct(
        public string $generated_at,
        public int $window_hours,
        public array $nodes,
        public array $repositories,
        public ?string $github_error_code,
        public array $jobs,
    ) {}
}
