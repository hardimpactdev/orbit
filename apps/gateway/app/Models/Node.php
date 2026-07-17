<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Nodes\InstalledAgentArtifactCast;
use App\Casts\Nodes\InstalledCliArtifactCast;
use App\Casts\Nodes\InstalledGatewayImageCast;
use App\Data\Nodes\InstalledAgentArtifact;
use App\Data\Nodes\InstalledCliArtifact;
use App\Data\Nodes\InstalledGatewayImage;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $tld
 * @property string|null $platform
 * @property string|null $architecture
 * @property string $host
 * @property string|null $wireguard_address
 * @property string|null $gateway_endpoint
 * @property string|null $public_ipv4
 * @property string|null $public_ipv6
 * @property array<string, mixed>|null $agent_ide_config
 * @property InstalledCliArtifact|null $installed_cli
 * @property InstalledAgentArtifact|null $installed_agent
 * @property InstalledGatewayImage|null $installed_gateway_image
 * @property string|null $host_key_type
 * @property string|null $host_key_fingerprint
 * @property string|null $host_key_public
 * @property Carbon|null $host_key_pinned_at
 * @property string|null $host_key_pin_mode
 * @property string|null $user
 * @property string $orbit_path
 * @property bool $managed
 * @property NodeStatus $status
 * @property-read Collection<int, NodeTool> $nodeTools
 * @property-read Collection<int, NodeRoleAssignment> $roleAssignments
 * @property-read Collection<int, FirewallRule> $firewallRules
 * @property-read Collection<int, Process> $processes
 * @property-read SchedulerState|null $schedulerState
 * @property-read Collection<int, Schedule> $schedules
 */
class Node extends Model
{
    use HasFactory;

    /** @var list<string> */
    private const array AGENT_WORKLOAD_ROLES = [
        'app-dev',
        'app-prod',
        'database',
        'agent',
        'ingress',
        'websocket',
        's3',
        'metrics',
        'analytics',
    ];

    /** @var list<string> */
    private const array AGENT_PLATFORM_PREFIXES = [
        'ubuntu',
        'macos',
        'darwin',
    ];

    /** @var list<string> */
    private const array INVALID_AGENT_ADDRESSES = [
        '0.0.0.0',
        '::',
        '127.0.0.1',
        '::1',
        '255.255.255.255',
    ];

    #[\Override]
    protected $fillable = [
        'name',
        'tld',
        'platform',
        'architecture',
        'host',
        'wireguard_address',
        'gateway_endpoint',
        'public_ipv4',
        'public_ipv6',
        'agent_ide_config',
        'installed_cli',
        'installed_agent',
        'installed_gateway_image',
        'host_key_type',
        'host_key_fingerprint',
        'host_key_public',
        'host_key_pinned_at',
        'host_key_pin_mode',
        'user',
        'orbit_path',
        'managed',
        'status',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'agent_ide_config' => 'array',
            'installed_cli' => InstalledCliArtifactCast::class,
            'installed_agent' => InstalledAgentArtifactCast::class,
            'installed_gateway_image' => InstalledGatewayImageCast::class,
            'host_key_pinned_at' => 'datetime',
            'managed' => 'bool',
            'status' => NodeStatus::class,
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

    /**
     * @return MorphMany<Process, $this>
     */
    public function processes(): MorphMany
    {
        return $this->morphMany(Process::class, 'owner')->orderBy('sort_order');
    }

    public function hasActiveRole(string $role): bool
    {
        if (! $this->relationLoaded('roleAssignments')) {
            return $this
                ->roleAssignments()
                ->where('role', $role)
                ->where('status', NodeRoleStatus::Active->value)
                ->exists();
        }

        return $this->roleAssignments
            ->whereStrict('role', $role)
            ->whereStrict('status', NodeRoleStatus::Active)
            ->isNotEmpty();
    }

    public function isActive(): bool
    {
        return $this->status === NodeStatus::Active;
    }

    public function isProvisioning(): bool
    {
        return $this->status === NodeStatus::Provisioning;
    }

    public function hasAgentIntent(): bool
    {
        if ($this->hasActiveRole('gateway')) {
            return false;
        }

        return in_array(
            true,
            [
                $this->hasAgentWorkloadRole(),
                ! in_array(false, [$this->managed, $this->isOperator()], strict: true),
            ],
            strict: true,
        );
    }

    public function isAgentEligible(): bool
    {
        return ! in_array(
            false,
            [
                $this->isActive() || $this->isProvisioning(),
                $this->hasAgentIntent(),
                $this->hasSupportedAgentPlatform(),
                $this->hasValidWireguardAddress(),
            ],
            strict: true,
        );
    }

    public function isAgentProvisioningReady(): bool
    {
        return $this->isProvisioning() && $this->hasSupportedAgentPlatform() && $this->hasValidWireguardAddress();
    }

    private function hasAgentWorkloadRole(): bool
    {
        if ($this->relationLoaded('roleAssignments')) {
            return $this->roleAssignments
                ->whereInStrict('role', self::AGENT_WORKLOAD_ROLES)
                ->whereInStrict('status', [
                    NodeRoleStatus::Pending,
                    NodeRoleStatus::Active,
                    NodeRoleStatus::Removing,
                ])
                ->isNotEmpty();
        }

        return $this
            ->roleAssignments()
            ->whereIn('role', self::AGENT_WORKLOAD_ROLES)
            ->whereIn('status', [
                NodeRoleStatus::Pending->value,
                NodeRoleStatus::Active->value,
                NodeRoleStatus::Removing->value,
            ])
            ->exists();
    }

    private function hasSupportedAgentPlatform(): bool
    {
        $platform = strtolower(trim((string) $this->platform));

        return array_any(
            self::AGENT_PLATFORM_PREFIXES,
            static fn (string $prefix): bool => str_starts_with($platform, $prefix),
        );
    }

    private function hasValidWireguardAddress(): bool
    {
        $address = trim((string) $this->wireguard_address);

        return ! in_array(
            false,
            [
                $address !== '',
                filter_var($address, FILTER_VALIDATE_IP) !== false,
                ! in_array($address, self::INVALID_AGENT_ADDRESSES, strict: true),
                preg_match('/^(?:22[4-9]|23\d)(?:\.|$)/', $address) !== 1,
                ! str_starts_with(strtolower($address), 'ff'),
            ],
            strict: true,
        );
    }

    public function isOperator(): bool
    {
        return ! $this
            ->roleAssignments()
            ->where('status', NodeRoleStatus::Active->value)
            ->exists();
    }

    public function displayRole(): string
    {
        if ($this->isOperator()) {
            return 'operator';
        }

        /** @var NodeRoleAssignment|null $primary */
        $primary = $this->roleAssignments()->where('status', NodeRoleStatus::Active->value)->orderBy('role')->first();

        return $primary?->role;
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
