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
 * @property string|null $step_set_hash
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AppInstance|null $appInstance
 * @property-read Collection<int, AppSetupRunStep> $runSteps
 */
class AppSetupRun extends Model
{
    use HasFactory;

    #[\Override]
    protected $fillable = [
        'app_instance_id',
        'status',
        'step_set_hash',
        'started_at',
        'completed_at',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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
     * @return HasMany<AppSetupRunStep, $this>
     */
    public function runSteps(): HasMany
    {
        return $this->hasMany(AppSetupRunStep::class)->orderBy('id');
    }
}
