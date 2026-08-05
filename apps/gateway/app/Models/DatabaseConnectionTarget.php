<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DatabaseConnectionTargetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $database_connection_id
 * @property int|null $instance_id
 * @property int|null $workspace_id
 * @property string $env_prefix
 * @property-read DatabaseConnection|null $connection
 * @property-read Instance|null $instance
 * @property-read Workspace|null $workspace
 */
class DatabaseConnectionTarget extends Model
{
    /** @use HasFactory<DatabaseConnectionTargetFactory> */
    use HasFactory;

    #[\Override]
    protected $fillable = [
        'database_connection_id',
        'instance_id',
        'workspace_id',
        'env_prefix',
    ];

    /**
     * @return BelongsTo<DatabaseConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(DatabaseConnection::class, 'database_connection_id');
    }

    /**
     * @return BelongsTo<Instance, $this>
     */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class);
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
