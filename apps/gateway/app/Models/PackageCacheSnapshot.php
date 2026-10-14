<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $project_id
 * @property bool $fill_pending
 * @property string|null $commit
 * @property array<string, string> $blobs
 * @property list<array{manager: string, package: string, version: string, url: string, integrity: string|null, key: string}> $artifacts
 */
final class PackageCacheSnapshot extends Model
{
    #[\Override]
    protected $primaryKey = 'project_id';

    #[\Override]
    public $incrementing = false;

    #[\Override]
    protected $guarded = [];

    protected function casts(): array
    {
        return ['blobs' => 'array', 'artifacts' => 'array', 'fill_pending' => 'boolean'];
    }
}
