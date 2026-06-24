<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $app_setup_run_id
 * @property int|null $app_setup_step_id
 * @property string $command
 * @property int|null $exit_code
 * @property string|null $output
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AppSetupRun|null $run
 * @property-read AppSetupStep|null $step
 */
class AppSetupRunStep extends Model
{
    use HasFactory;

    #[\Override]
    protected $fillable = [
        'app_setup_run_id',
        'app_setup_step_id',
        'command',
        'exit_code',
        'output',
        'started_at',
        'completed_at',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'exit_code' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AppSetupRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AppSetupRun::class, 'app_setup_run_id');
    }

    /**
     * @return BelongsTo<AppSetupStep, $this>
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(AppSetupStep::class, 'app_setup_step_id');
    }
}
