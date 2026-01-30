<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $repo_url
 * @property string $display_name
 * @property int $usage_count
 * @property \Carbon\Carbon|null $last_used_at
 * @property string|null $db_driver
 * @property string|null $session_driver
 * @property string|null $cache_driver
 * @property string|null $queue_driver
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class TemplateFavorite extends Model
{
    protected $fillable = [
        'repo_url',
        'display_name',
        'usage_count',
        'last_used_at',
        'db_driver',
        'session_driver',
        'cache_driver',
        'queue_driver',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
    ];

    public function recordUsage(array $drivers = []): void
    {
        $this->increment('usage_count');

        $data = ['last_used_at' => now()];

        foreach (['db_driver', 'session_driver', 'cache_driver', 'queue_driver'] as $key) {
            if (isset($drivers[$key]) && $drivers[$key] !== null) {
                $data[$key] = $drivers[$key];
            }
        }

        $this->update($data);
    }

    public static function recentlyUsed(int $limit = 10)
    {
        return static::orderByDesc('last_used_at')
            ->limit($limit)
            ->get();
    }

    public static function mostUsed(int $limit = 10)
    {
        return static::orderByDesc('usage_count')
            ->limit($limit)
            ->get();
    }
}
