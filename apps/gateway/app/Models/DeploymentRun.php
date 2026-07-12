<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $app_instance_id
 * @property string $status
 * @property int|null $exit_code
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int|null $duration_ms
 * @property array<string, mixed>|null $context
 * @property-read AppInstance $appInstance
 * @property-read Collection<int, DeploymentRunStep> $steps
 */
class DeploymentRun extends Model
{
    use HasFactory;

    #[\Override]
    protected $fillable = [
        'app_instance_id',
        'status',
        'exit_code',
        'started_at',
        'finished_at',
        'duration_ms',
        'context',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'context' => 'array',
        ];
    }

    /**
     * @return BelongsTo<AppInstance, $this>
     */
    public function appInstance(): BelongsTo
    {
        return $this->belongsTo(AppInstance::class);
    }

    /**
     * @return HasMany<DeploymentRunStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(DeploymentRunStep::class)->orderBy('id');
    }
}
