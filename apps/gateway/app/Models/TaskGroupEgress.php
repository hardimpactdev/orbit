<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One outside host that a task group's VM connected to or asked DNS for, as the host's agent reported it
 * (docs/reference/task-vm-egress-log.md).
 *
 * @property int $id
 * @property int $task_id
 * @property string $vm_name the VM that first showed the row
 * @property string $destination a name, or an IPv4 address when the VM never resolved one
 * @property string $kind name|address
 * @property int $port 0 for a name that nothing connected to
 * @property string $protocol tcp|udp, '' for a name that nothing connected to
 * @property list<string> $addresses
 * @property list<string> $sources conn|dns
 * @property list<string> $flags dot|dns|doh
 * @property Carbon $first_seen_at
 */
final class TaskGroupEgress extends Model
{
    #[\Override]
    protected $table = 'task_group_egress';

    #[\Override]
    public $timestamps = false;

    #[\Override]
    protected $guarded = [];

    /** @return BelongsTo<Task, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'addresses' => 'array',
            'sources' => 'array',
            'flags' => 'array',
            'first_seen_at' => 'datetime',
        ];
    }
}
