<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AppDevelopmentSetupStepFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $app_id
 * @property int $sort_order
 * @property string $command
 * @property int $timeout_seconds
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read App|null $app
 */
class AppDevelopmentSetupStep extends Model
{
    /** @use HasFactory<AppDevelopmentSetupStepFactory> */
    use HasFactory;

    public const int DEFAULT_TIMEOUT_SECONDS = 600;

    #[\Override]
    protected $fillable = ['app_id', 'sort_order', 'command', 'timeout_seconds'];

    #[\Override]
    protected function casts(): array
    {
        return ['timeout_seconds' => 'integer', 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<App, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }
}
