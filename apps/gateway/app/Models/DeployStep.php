<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $instance_id
 * @property string $title
 * @property string $command
 * @property int $sort_order
 * @property int $timeout_seconds
 * @property int|null $retention
 * @property-read Instance $instance
 */
class DeployStep extends Model
{
    use HasFactory;

    public const int DEFAULT_TIMEOUT_SECONDS = 600;

    #[\Override]
    protected $fillable = [
        'instance_id',
        'title',
        'command',
        'sort_order',
        'timeout_seconds',
        'retention',
    ];

    /**
     * @return BelongsTo<Instance, $this>
     */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class);
    }
}
