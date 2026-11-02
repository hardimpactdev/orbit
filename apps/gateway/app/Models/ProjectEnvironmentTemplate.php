<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Project's environment template, built from the committed `.env.example` on its default branch.
 *
 * Each entry holds the template `value`, the `example` value it was last built from, and whether
 * `.env.example` has `removed` the key. A value that differs from its example is an Orbit or operator edit.
 *
 * @property int $id
 * @property int $project_id
 * @property list<array{key: string, value: string, example: string|null, removed: bool}> $entries
 * @property string $source_path
 * @property string $source_commit
 * @property string|null $source_blob
 * @property string $checked_head
 * @property-read Project $project
 */
final class ProjectEnvironmentTemplate extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = ['project_id', 'entries', 'source_path', 'source_commit', 'source_blob', 'checked_head'];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['entries' => 'array'];
    }
}
