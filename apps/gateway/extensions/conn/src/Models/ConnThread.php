<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Models;

use App\Models\Instance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thread of a registered server in the thread index (docs/reference/conn.md#thread-index). `seen_at` is when the Gateway last
 * read the thread on its server, or heard of it through a write-through. It holds no message text beyond the title.
 *
 * @property int $id
 * @property int $conn_environment_id
 * @property int|null $instance_id
 * @property int|null $orbit_project_id
 * @property string $thread_id
 * @property string $title
 * @property string|null $state
 * @property bool $needs_input
 * @property Carbon|null $last_user_message_at
 * @property Carbon|null $last_run_completed_at
 * @property Carbon $seen_at
 * @property-read ConnEnvironment $environment
 * @property-read Instance|null $instance
 */
final class ConnThread extends Model
{
    public const string HostTable = 'conn_host_threads';

    /** @return Builder<self> */
    public static function hostQuery(): Builder
    {
        return (new self)->setTable(self::HostTable)->newQuery();
    }

    /** @return Builder<self> */
    public static function forEnvironment(ConnEnvironment $environment): Builder
    {
        return ($environment->instance_id === null ? self::hostQuery() : self::query())->where('conn_environment_id', $environment->id);
    }

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'conn_environment_id',
        'instance_id',
        'orbit_project_id',
        'thread_id',
        'title',
        'state',
        'needs_input',
        'last_user_message_at',
        'last_run_completed_at',
        'seen_at',
    ];

    /** @var array<string, bool> */
    #[\Override]
    protected $attributes = ['needs_input' => false];

    /** @return BelongsTo<ConnEnvironment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(ConnEnvironment::class, 'conn_environment_id');
    }

    /** @return BelongsTo<Instance, $this> */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'conn_environment_id' => 'integer',
            'instance_id' => 'integer',
            'orbit_project_id' => 'integer',
            'needs_input' => 'boolean',
            'last_user_message_at' => 'datetime',
            'last_run_completed_at' => 'datetime',
            'seen_at' => 'datetime',
        ];
    }
}
