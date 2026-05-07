<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $app_id
 * @property string $title
 * @property string $command
 * @property int $sort_order
 * @property int $timeout_seconds
 * @property int|null $retention
 * @property-read App|null $app
 */
class DeployStep extends Model
{
    use HasFactory;

    public const int DEFAULT_TIMEOUT_SECONDS = 600;

    protected $fillable = [
        'app_id',
        'title',
        'command',
        'sort_order',
        'timeout_seconds',
        'retention',
    ];

    /**
     * @return BelongsTo<App, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    public static function createOrdered(
        int $appId,
        string $title,
        string $command,
        int $timeoutSeconds,
        ?int $order = null,
        ?int $retention = null,
    ): self {
        return DB::transaction(function () use ($appId, $title, $command, $timeoutSeconds, $order, $retention): self {
            $nextOrder = ((int) self::query()->where('app_id', $appId)->max('sort_order')) + 1;
            $targetOrder = max(1, min($order ?? $nextOrder, $nextOrder));

            self::query()
                ->where('app_id', $appId)
                ->where('sort_order', '>=', $targetOrder)
                ->orderByDesc('sort_order')
                ->each(function (self $step): void {
                    $step->forceFill(['sort_order' => $step->sort_order + 1])->save();
                });

            return self::query()->create([
                'app_id' => $appId,
                'title' => $title,
                'command' => $command,
                'sort_order' => $targetOrder,
                'timeout_seconds' => $timeoutSeconds,
                'retention' => $retention,
            ]);
        });
    }

    public function deleteAndCompact(): void
    {
        DB::transaction(function (): void {
            $appId = $this->app_id;
            $order = $this->sort_order;

            $this->delete();

            self::query()
                ->where('app_id', $appId)
                ->where('sort_order', '>', $order)
                ->orderBy('sort_order')
                ->each(function (self $step): void {
                    $step->forceFill(['sort_order' => $step->sort_order - 1])->save();
                });
        });
    }
}
