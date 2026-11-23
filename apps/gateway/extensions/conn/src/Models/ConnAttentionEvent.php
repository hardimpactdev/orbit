<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attention or attention_cleared event reported by a Conn Server, deduplicated by ULID event_id and pruned after 7 days.
 *
 * @property int $id
 * @property string $event_id
 * @property int $conn_environment_id
 * @property string $environment_id
 * @property string $thread_id
 * @property string $type
 * @property string|null $kind
 * @property string|null $request_id
 * @property string|null $thread_title
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read ConnEnvironment $environment
 */
final class ConnAttentionEvent extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'event_id',
        'conn_environment_id',
        'environment_id',
        'thread_id',
        'type',
        'kind',
        'request_id',
        'thread_title',
        'occurred_at',
    ];

    /** @var list<string> */
    #[\Override]
    protected $hidden = ['thread_title'];

    /** @return BelongsTo<ConnEnvironment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(ConnEnvironment::class, 'conn_environment_id');
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'conn_environment_id' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }
}
