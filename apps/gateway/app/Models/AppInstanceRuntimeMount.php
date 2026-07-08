<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $app_instance_id
 * @property string $source
 * @property string $target
 * @property bool $read_only
 * @property-read AppInstance $appInstance
 */
class AppInstanceRuntimeMount extends Model
{
    #[\Override]
    protected $fillable = [
        'app_instance_id',
        'source',
        'target',
        'read_only',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'read_only' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<AppInstance, $this>
     */
    public function appInstance(): BelongsTo
    {
        return $this->belongsTo(AppInstance::class);
    }
}
