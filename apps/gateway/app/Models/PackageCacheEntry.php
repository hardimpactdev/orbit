<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $project_id
 * @property int $host_node_id
 * @property string $manager
 * @property string $package
 * @property string $version
 * @property string $integrity
 * @property string $key
 */
final class PackageCacheEntry extends Model
{
    #[\Override]
    protected $guarded = [];
}
