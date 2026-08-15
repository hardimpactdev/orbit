<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AppAnalyticsBindingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property int $id
 * @property int $instance_id
 * @property bool $enabled
 * @property list<string> $public_hosts
 * @property-read Instance $instance
 */
class AppAnalyticsBinding extends Model
{
    /** @use HasFactory<AppAnalyticsBindingFactory> */
    use HasFactory;

    #[Override]
    protected $table = 'app_analytics_bindings';

    #[Override]
    protected $fillable = [
        'instance_id',
        'enabled',
        'public_hosts',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'public_hosts' => 'array',
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
