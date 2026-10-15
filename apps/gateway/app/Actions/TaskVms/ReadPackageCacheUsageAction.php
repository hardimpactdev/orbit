<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Models\PackageCacheEntry;
use App\Models\PackageCacheSnapshot;
use App\Models\Project;

final readonly class ReadPackageCacheUsageAction
{
    /** @return array{projects: list<array{id: int, name: string}>, cached: list<array{project_id: int, host_node_id: int, manager: string, integrity: string}>} */
    public function execute(string $package, string $version): array
    {
        $projects = [];
        $keys = [];
        foreach (PackageCacheSnapshot::query()->get() as $snapshot) {
            if (array_any($snapshot->artifacts, static fn (array $artifact): bool => $artifact['package'] === $package && $artifact['version'] === $version)) {
                $projects[] = $snapshot->project_id;
                foreach ($snapshot->artifacts as $artifact) {
                    if ($artifact['package'] === $package && $artifact['version'] === $version) {
                        $keys[$snapshot->project_id][$artifact['key']] = true;
                    }
                }
            }
        }

        $names = [];
        foreach (Project::query()->whereKey($projects)->orderBy('id')->get(['id', 'name']) as $project) {
            $names[] = ['id' => $project->id, 'name' => $project->name];
        }
        $cached = [];
        foreach (PackageCacheEntry::query()->whereIn('project_id', $projects)->where('package', $package)->where('version', $version)->orderBy('project_id')->orderBy('host_node_id')->get() as $entry) {
            if (! isset($keys[$entry->project_id][$entry->key])) {
                continue;
            }
            $cached[] = ['project_id' => $entry->project_id, 'host_node_id' => $entry->host_node_id, 'manager' => $entry->manager, 'integrity' => $entry->integrity];
        }

        return ['projects' => $names, 'cached' => $cached];
    }
}
