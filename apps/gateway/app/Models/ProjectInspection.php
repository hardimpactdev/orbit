<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The last dependency inspection of a Project's default branch (`project:maintenance:inspect`).
 *
 * `manifests` maps each source to the blob SHAs of its manifests and lockfiles; `sources` holds each source's
 * installed packages. The next inspection reads again only the sources whose SHAs changed, and asks the
 * registries again once `registry_checked_at` is a day old.
 *
 * @property int $id
 * @property int $project_id
 * @property string $source_commit
 * @property array<string, array<string, string>> $manifests
 * @property list<array<string, mixed>> $sources
 * @property list<array<string, mixed>> $targets
 * @property list<array<string, mixed>> $blocked
 * @property CarbonImmutable $inspected_at
 * @property CarbonImmutable|null $registry_checked_at
 * @property-read Project $project
 */
final class ProjectInspection extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = ['project_id', 'source_commit', 'manifests', 'sources', 'targets', 'blocked', 'inspected_at', 'registry_checked_at'];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['manifests' => 'array', 'sources' => 'array', 'targets' => 'array', 'blocked' => 'array',
            'inspected_at' => 'immutable_datetime', 'registry_checked_at' => 'immutable_datetime'];
    }
}
