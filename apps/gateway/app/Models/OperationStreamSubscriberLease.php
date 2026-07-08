<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $operation_run_id
 * @property string $channel
 * @property string $subscriber
 * @property Carbon $expires_at
 * @property Carbon|null $left_at
 * @property-read OperationRun $operationRun
 */
class OperationStreamSubscriberLease extends Model
{
    #[\Override]
    protected $fillable = [
        'operation_run_id',
        'channel',
        'subscriber',
        'expires_at',
        'left_at',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<OperationRun, $this>
     */
    public function operationRun(): BelongsTo
    {
        return $this->belongsTo(OperationRun::class);
    }
}
