<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $environment_id
 * @property string $name
 * @property string|null $path
 * @property array|null $projects
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read Environment $environment
 * @property-read int $project_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> where(string $column, mixed $operator = null, mixed $value = null, string $boolean = 'and')
 * @method static static create(array $attributes = [])
 */
class Workspace extends Model
{
    use HasFactory;

    protected $fillable = [
        'environment_id',
        'name',
        'path',
        'projects',
    ];

    protected $casts = [
        'projects' => 'array',
    ];

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * Get the number of projects in this workspace.
     */
    public function getProjectCountAttribute(): int
    {
        return count($this->projects ?? []);
    }

    /**
     * Check if workspace has a .code-workspace file.
     *
     * NOTE: This is an explicit method (not an accessor) because it performs
     * filesystem I/O. Call only when needed to avoid N file_exists() calls
     * when iterating over collections.
     */
    public function hasWorkspaceFile(): bool
    {
        if (! $this->path) {
            return false;
        }

        $workspaceFile = rtrim($this->path, '/').'/'.$this->name.'.code-workspace';

        return file_exists($workspaceFile);
    }

    /**
     * Add a project to this workspace.
     */
    public function addProject(string $projectName): void
    {
        $projects = $this->projects ?? [];
        if (! in_array($projectName, $projects)) {
            $projects[] = $projectName;
            $this->projects = $projects;
            $this->save();
        }
    }

    /**
     * Remove a project from this workspace.
     */
    public function removeProject(string $projectName): void
    {
        $projects = $this->projects ?? [];
        $this->projects = array_values(array_filter($projects, fn ($p) => $p !== $projectName));
        $this->save();
    }

    /**
     * Convert to array format expected by frontend.
     */
    public function toFrontendArray(): array
    {
        return [
            'name' => $this->name,
            'path' => $this->path,
            'projects' => collect($this->projects ?? [])->map(fn ($name) => [
                'name' => $name,
                'path' => null, // Will be filled by caller if needed
            ])->all(),
            'project_count' => $this->project_count,
            'has_workspace_file' => $this->hasWorkspaceFile(),
            'has_claude_md' => false, // TODO: implement
        ];
    }
}
