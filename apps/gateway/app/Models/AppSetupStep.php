<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $instance_id
 * @property int $sort_order
 * @property string $command
 * @property int $timeout_seconds
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Instance|null $instance
 */
class AppSetupStep extends Model
{
    use HasFactory;

    public const int DEFAULT_TIMEOUT_SECONDS = 600;

    #[\Override]
    protected $fillable = [
        'instance_id',
        'sort_order',
        'command',
        'timeout_seconds',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'timeout_seconds' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Instance, $this>
     */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class);
    }

    public function timeoutSeconds(): int
    {
        return $this->timeout_seconds;
    }
}
