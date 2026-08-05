<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $instance_id
 * @property string $source
 * @property string $target
 * @property bool $read_only
 * @property-read Instance $instance
 */
class InstanceRuntimeMount extends Model
{
    #[\Override]
    protected $table = 'instance_runtime_mounts';

    #[\Override]
    protected $fillable = [
        'instance_id',
        'source',
        'target',
        'read_only',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'read_only' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Instance, $this>
     */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class);
    }
}
