<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $instance_id
 * @property string $key
 * @property string|null $value
 * @property bool $secret
 * @property-read Instance $instance
 */
class InstanceEnvVariable extends Model
{
    #[\Override]
    protected $table = 'instance_env_variables';

    #[\Override]
    protected $fillable = [
        'instance_id',
        'key',
        'value',
        'secret',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'secret' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Instance, $this>
     */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class, 'instance_id');
    }
}
