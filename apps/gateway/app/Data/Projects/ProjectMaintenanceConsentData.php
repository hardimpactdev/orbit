<?php

declare(strict_types=1);

namespace App\Data\Projects;

use App\Models\Project;
use Spatie\LaravelData\Data;

final class ProjectMaintenanceConsentData extends Data
{
    public function __construct(public int $project_id, public bool $enabled) {}

    public static function fromModel(Project $project): self
    {
        return new self($project->id, $project->maintenance_enabled);
    }
}
