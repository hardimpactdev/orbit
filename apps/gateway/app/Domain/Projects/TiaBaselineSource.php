<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Infrastructure\GitHub\HttpGitHubTiaBaseline;
use App\Models\Project;
use Illuminate\Container\Attributes\Bind;

#[Bind(HttpGitHubTiaBaseline::class)]
interface TiaBaselineSource
{
    public function fetch(Project $project): TiaBaselineFiles;
}
