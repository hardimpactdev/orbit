<?php

declare(strict_types=1);

namespace App\Data\Ci;

use Spatie\LaravelData\Data;

/** The GitHub part of the runner view: the repositories read and their self-hosted jobs, newest first. */
final class CiJobListData extends Data
{
    /**
     * @param  list<CiRepositoryData>  $repositories
     * @param  list<CiJobData>  $jobs
     */
    public function __construct(
        public ?string $github_error_code,
        public array $repositories,
        public array $jobs,
    ) {}
}
