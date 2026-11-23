<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One APNs delivery record for a Conn push event. Never stores tokens, JWTs, or thread titles.
 *
 * @property int $id
 * @property string $event_id
 * @property int $conn_push_device_id
 * @property string $environment_id
 * @property string $thread_id
 * @property string $apns_environment
 * @property string $push_type
 * @property bool $is_summary
 * @property int $apns_status
 * @property string|null $apns_id
 * @property string|null $apns_reason
 * @property string|null $device_deleted_reason
 * @property Carbon $created_at
 */
final class ConnPushSend extends Model
{
    public const null UPDATED_AT = null;

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'event_id',
        'conn_push_device_id',
        'environment_id',
        'thread_id',
        'apns_environment',
        'push_type',
        'is_summary',
        'apns_status',
        'apns_id',
        'apns_reason',
        'device_deleted_reason',
        'created_at',
    ];

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'conn_push_device_id' => 'integer',
            'is_summary' => 'boolean',
            'apns_status' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
