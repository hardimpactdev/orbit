<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $lease_id
 * @property string $operation
 * @property string $binding
 * @property string $status
 * @property array<string, mixed> $connection
 */
final class TaskWorkspaceOperation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['lease_id' => 'integer', 'connection' => 'array'];
    }
}
