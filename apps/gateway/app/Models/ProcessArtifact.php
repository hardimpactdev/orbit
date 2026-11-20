<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The release artifact of a systemd Process (docs/reference/processes-and-schedules.md#upgrade-a-process).
 *
 * @property int $id
 * @property int $process_id
 * @property string $source
 * @property string $repository
 * @property string $asset
 * @property string $binary
 * @property ?string $checksums
 * @property ?list<string> $version_args
 * @property ?string $version
 * @property ?string $previous_version
 * @property ?string $sha256
 * @property ?string $latest_version
 * @property ?Carbon $latest_checked_at
 * @property-read Process $process
 */
final class ProcessArtifact extends Model
{
    public const string SourceGithubRelease = 'github_release';

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'process_id',
        'source',
        'repository',
        'asset',
        'binary',
        'checksums',
        'version_args',
        'version',
        'previous_version',
        'sha256',
        'latest_version',
        'latest_checked_at',
    ];

    /** @return BelongsTo<Process, $this> */
    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'source' => $this->source,
            'repository' => $this->repository,
            'asset' => $this->asset,
            'binary' => $this->binary,
            'checksums' => $this->checksums,
            'version_args' => $this->version_args,
            'version' => $this->version,
            'previous_version' => $this->previous_version,
            'sha256' => $this->sha256,
            'latest_version' => $this->latest_version,
            'latest_checked_at' => $this->latest_checked_at?->toIso8601String(),
        ];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version_args' => 'array',
            'latest_checked_at' => 'datetime',
        ];
    }
}
