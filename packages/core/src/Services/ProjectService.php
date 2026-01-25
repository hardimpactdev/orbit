<?php

namespace HardImpact\Orbit\Core\Services;

use HardImpact\Orbit\Core\Models\Project;
use Illuminate\Support\Collection;

class ProjectService
{
    public function all(): Collection
    {
        return Project::all();
    }

    public function findBySlug(string $slug): ?Project
    {
        return Project::where('slug', $slug)->first();
    }

    public function create(array $data): Project
    {
        return Project::create($data);
    }

    public function update(Project $project, array $data): bool
    {
        return $project->update($data);
    }

    public function delete(Project $project): bool
    {
        return $project->delete();
    }
}
