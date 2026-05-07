<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $app_id
 * @property string $status
 * @property int|null $exit_code
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int|null $duration_ms
 * @property-read App|null $app
 */
class DeploymentRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'app_id',
        'status',
        'exit_code',
        'started_at',
        'finished_at',
        'duration_ms',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<App, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    /**
     * @return HasMany<DeploymentRunStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(DeploymentRunStep::class)->orderBy('id');
    }
}
