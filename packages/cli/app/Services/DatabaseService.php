<?php

namespace App\Services;

use HardImpact\Orbit\Core\Models\Project;

class DatabaseService
{
    public function getSiteOverride(string $slug): ?array
    {
        try {
            $project = Project::where('slug', $slug)->first();

            return $project ? $project->toArray() : null;
        } catch (\Exception) {
            return null;
        }
    }

    public function setSitePhpVersion(string $slug, string $path, ?string $version): void
    {
        try {
            Project::updateOrCreate(
                ['slug' => $slug],
                [
                    'path' => $path,
                    'php_version' => $version,
                ]
            );
        } catch (\Exception) {
            // Silently fail if database not available
        }
    }

    public function setSitePath(string $slug, string $path): void
    {
        try {
            Project::updateOrCreate(
                ['slug' => $slug],
                ['path' => $path]
            );
        } catch (\Exception) {
            // Silently fail if database not available
        }
    }

    public function getSitePath(string $slug): ?string
    {
        try {
            $project = Project::where('slug', $slug)->first();

            return $project?->getAttribute('path');
        } catch (\Exception) {
            return null;
        }
    }

    public function getAllSiteSlugs(): array
    {
        try {
            return Project::pluck('slug')->toArray();
        } catch (\Exception) {
            return [];
        }
    }

    public function deleteSite(string $slug): void
    {
        try {
            Project::where('slug', $slug)->delete();
        } catch (\Exception) {
            // Silently fail if database not available
        }
    }

    public function removeSiteOverride(string $slug): void
    {
        try {
            Project::where('slug', $slug)->delete();
        } catch (\Exception) {
            // Silently fail if database not available
        }
    }

    public function getAllOverrides(): array
    {
        try {
            return Project::whereNotNull('php_version')->get()->toArray();
        } catch (\Exception) {
            return [];
        }
    }

    public function getPhpVersion(string $slug): ?string
    {
        $override = $this->getSiteOverride($slug);

        return $override['php_version'] ?? null;
    }

    /**
     * Get the database path (useful for testing).
     */
    public function getDbPath(): string
    {
        return config('database.connections.sqlite.database');
    }

    /**
     * Get the raw PDO connection (for migrations/schema inspection).
     */
    public function getPdo(): ?\PDO
    {
        try {
            return \Illuminate\Support\Facades\DB::connection()->getPdo();
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Clear all data from the database (useful for testing).
     */
    public function truncate(): void
    {
        try {
            Project::truncate();
        } catch (\Exception) {
            // Silently fail if database not available
        }
    }
}
