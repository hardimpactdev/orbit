<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Instances\InstanceRemovalKind;
use App\Domain\Instances\InstanceRemovalStatus;
use App\Domain\Instances\InstanceRemovalStep;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $requested_instance_id
 * @property string $requested_name
 * @property bool $force
 * @property InstanceRemovalKind $kind
 * @property string $inventory_digest
 * @property array<string,mixed>|null $native_sources
 * @property array<string,mixed>|null $native_intent
 * @property int $total
 * @property InstanceRemovalStatus $status
 * @property InstanceRemovalStep|null $current_step
 * @property InstanceRemovalStep|null $failed_step
 * @property string|null $error_code
 * @property Carbon|null $route_withdrawn_at
 * @property int $retry_count
 * @property Carbon|null $stuck_at
 * @property string|null $start_refusal
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, InstanceRemovalMember> $members
 */
final class InstanceRemoval extends Model
{
    #[\Override]
    protected $table = 'instance_removals';

    #[\Override]
    public $incrementing = false;

    #[\Override]
    protected $keyType = 'string';

    /** @var array<string, mixed> */
    #[\Override]
    protected $attributes = ['kind' => 'destroy', 'retry_count' => 0];

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'id',
        'requested_instance_id',
        'requested_name',
        'force',
        'kind',
        'inventory_digest',
        'total',
        'native_intent',
        'native_sources',
        'status',
        'current_step',
        'failed_step',
        'error_code',
        'route_withdrawn_at',
        'retry_count',
        'stuck_at',
        'start_refusal',
    ];

    /** @return HasMany<InstanceRemovalMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(InstanceRemovalMember::class, 'instance_removal_id')->orderBy('position');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'force' => 'boolean',
            'kind' => InstanceRemovalKind::class,
            'native_intent' => 'array',
            'native_sources' => 'array',
            'status' => InstanceRemovalStatus::class,
            'current_step' => InstanceRemovalStep::class,
            'failed_step' => InstanceRemovalStep::class,
            'route_withdrawn_at' => 'datetime',
            'retry_count' => 'integer',
            'stuck_at' => 'datetime',
        ];
    }
}
