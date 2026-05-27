<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $role
 * @property string|null $environment
 * @property string|null $tld
 * @property string|null $platform
 * @property string $host
 * @property string|null $wireguard_address
 * @property string|null $gateway_endpoint
 * @property string|null $public_ipv4
 * @property string|null $public_ipv6
 * @property array<string, mixed>|null $agent_ide_config
 * @property string|null $host_key_type
 * @property string|null $host_key_fingerprint
 * @property string|null $host_key_public
 * @property Carbon|null $host_key_pinned_at
 * @property string|null $host_key_pin_mode
 * @property string|null $user
 * @property string $orbit_path
 * @property string $status
 * @property-read Collection<int, NodeTool> $nodeTools
 * @property-read Collection<int, NodeRoleAssignment> $roleAssignments
 * @property-read Collection<int, FirewallRule> $firewallRules
 * @property-read SchedulerState|null $schedulerState
 * @property-read Collection<int, Schedule> $schedules
 */
class Node extends Model
{
    use HasFactory;

    public const string STATUS_PROVISIONING = 'provisioning';

    public const string STATUS_ACTIVE = 'active';

    #[\Override]
    protected $fillable = [
        'name',
        'role',
        'environment',
        'tld',
        'platform',
        'host',
        'wireguard_address',
        'gateway_endpoint',
        'public_ipv4',
        'public_ipv6',
        'agent_ide_config',
        'host_key_type',
        'host_key_fingerprint',
        'host_key_public',
        'host_key_pinned_at',
        'host_key_pin_mode',
        'user',
        'orbit_path',
        'status',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'agent_ide_config' => 'array',
            'host_key_pinned_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<Node, $this>
     */
    public function consumingNodes(): BelongsToMany
    {
        return $this->belongsToMany(
            related: self::class,
            table: 'node_access',
            foreignPivotKey: 'serving_node_id',
            relatedPivotKey: 'consumer_node_id',
        )->withPivot('permissions');
    }

    /**
     * @return BelongsToMany<Node, $this>
     */
    public function servingNodes(): BelongsToMany
    {
        return $this->belongsToMany(
            related: self::class,
            table: 'node_access',
            foreignPivotKey: 'consumer_node_id',
            relatedPivotKey: 'serving_node_id',
        )->withPivot('permissions');
    }

    /**
     * @return HasOne<SchedulerState, $this>
     */
    public function schedulerState(): HasOne
    {
        return $this->hasOne(SchedulerState::class);
    }

    /**
     * @return HasMany<Schedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class)->orderBy('name');
    }

    /**
     * @return HasMany<NodeTool, $this>
     */
    public function nodeTools(): HasMany
    {
        return $this->hasMany(NodeTool::class)->orderBy('name');
    }

    /**
     * @return HasMany<NodeRoleAssignment, $this>
     */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(NodeRoleAssignment::class)->orderBy('role');
    }

    public function hasActiveRole(string $role): bool
    {
        if (! $this->relationLoaded('roleAssignments')) {
            return $this->roleAssignments()
                ->where('role', $role)
                ->where('status', 'active')
                ->exists();
        }

        return $this->roleAssignments
            ->contains(fn (NodeRoleAssignment $assignment): bool => $assignment->role === $role && $assignment->status === 'active');
    }

    public function isOperator(): bool
    {
        return $this->role === 'control';
    }

    public function displayRole(): string
    {
        return $this->isOperator() ? 'operator' : $this->role;
    }

    /**
     * @return HasMany<FirewallRule, $this>
     */
    public function firewallRules(): HasMany
    {
        return $this->hasMany(FirewallRule::class)->orderBy('name');
    }

    /**
     * @return HasMany<ScheduleLock, $this>
     */
    public function scheduleLocks(): HasMany
    {
        return $this->hasMany(ScheduleLock::class);
    }

    /**
     * @return HasMany<ScheduleRun, $this>
     */
    public function scheduleRuns(): HasMany
    {
        return $this->hasMany(ScheduleRun::class);
    }
}
